// lynomia-agent — وكيلُ النقاط الطرفية (الطور K · WP-K.1/K.2 · §45–62): واجهةٌ
// نحيلة بأربعة أوامر لا غير — `enroll` (تسجيلٌ بالرمز) و`run` (حلقةُ
// البروتوكول: نبضٌ/أحداثٌ/أوامر) و`update` (تحديثٌ ذاتيٌّ متحقَّقُ التجزئة)
// و`version`.
//
// قواعدُ الطور K سارية هنا وفي كل الشجرة: **لا shell إطلاقاً** (لا استيرادَ
// لحزمة إطلاق العمليات في أي ملف — يثبته برهانُ internal/guardrails)، لا
// جامعاتِ مراقبة، والمفتاحُ الخاصُّ لا يغادر الجهاز.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"time"

	"lynomia/agent/internal/enrollment"
	"lynomia/agent/internal/identity"
	"lynomia/agent/internal/update"
)

// Version — نسخةُ الوكيل (تسافر agent_version عند التسجيل والنبض).
const Version = "0.2.0"

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}
	switch os.Args[1] {
	case "version":
		fmt.Println("lynomia-agent v" + Version)
	case "enroll":
		if err := cmdEnroll(os.Args[2:]); err != nil {
			fmt.Fprintln(os.Stderr, "خطأ:", err)
			os.Exit(1)
		}
	case "run":
		if err := cmdRun(os.Args[2:]); err != nil {
			fmt.Fprintln(os.Stderr, "خطأ:", err)
			os.Exit(1)
		}
	case "update":
		if err := cmdUpdate(os.Args[2:]); err != nil {
			fmt.Fprintln(os.Stderr, "خطأ:", err)
			os.Exit(1)
		}
	default:
		usage()
		os.Exit(2)
	}
}

func usage() {
	fmt.Fprintln(os.Stderr, `الاستعمال: lynomia-agent <أمر>

  enroll --server <URL> --token <رمز> [--dir <مجلد>] [--hostname <اسم>] [--force]
         يولّد هويّةَ الجهاز (إن لم توجد) ويسجّله لدى الخادم — المفتاحُ العامّ وحدَه يُرسَل.
  run    [--dir <مجلد>] [--once] [--interval-min <دقائق>]
         حلقةُ البروتوكول الموقَّعة: نبضٌ (جردٌ + وضعيّةٌ صادقة) وأحداثٌ
         (network_self/usb) وسحبُ أوامر القائمة المغلقة وإبلاغُ نتائجها موقَّعةً.
  update --manifest <URL> [--target <مسار>]
         تحديثٌ ذاتيّ: يجلب البيان {url, sha256} ويرفض أيَّ انحرافِ تجزئةٍ قبل التبديل.
  version
         يطبع نسخةَ الوكيل.`)
}

func stateDir(flagValue string) (string, error) {
	if flagValue != "" {
		return flagValue, nil
	}
	return identity.DefaultDir()
}

func cmdEnroll(args []string) error {
	fs := flag.NewFlagSet("enroll", flag.ExitOnError)
	server := fs.String("server", "", "عنوانُ الخادم (مثل https://hub.example.com)")
	token := fs.String("token", "", "رمزُ التسجيل المسكوك من مركز النقاط الطرفية")
	dirFlag := fs.String("dir", "", "مجلدُ الهويّة والحالة (الافتراضيّ: مجلدُ إعدادات المستخدم)")
	hostname := fs.String("hostname", "", "اسمُ مضيفٍ صريح (الافتراضيّ: اسمُ النظام)")
	force := fs.Bool("force", false, "إعادةُ التسجيل رغم حالةٍ قائمة")
	if err := fs.Parse(args); err != nil {
		return err
	}
	if *server == "" || *token == "" {
		return errors.New("‏--server و--token مطلوبان")
	}

	dir, err := stateDir(*dirFlag)
	if err != nil {
		return err
	}
	id, err := identity.LoadOrGenerate(dir)
	if err != nil {
		return err
	}
	fp, err := id.Fingerprint()
	if err != nil {
		return err
	}

	st, err := enrollment.Enroll(dir, id, enrollment.Options{
		Server:       *server,
		Token:        *token,
		AgentVersion: Version,
		Hostname:     *hostname,
		Force:        *force,
	})
	if err != nil {
		return err
	}

	fmt.Println("سُجّل الجهاز.")
	fmt.Println("  device_id :", st.DeviceID)
	fmt.Println("  fingerprint:", fp)
	fmt.Println("  heartbeat :", st.HeartbeatPath)
	return nil
}

func cmdRun(args []string) error {
	fs := flag.NewFlagSet("run", flag.ExitOnError)
	dirFlag := fs.String("dir", "", "مجلدُ الهويّة والحالة")
	once := fs.Bool("once", false, "دورةٌ واحدة (نبضٌ + أوامر) ثم خروج")
	intervalMin := fs.Int("interval-min", 0, "إيقاعٌ صريحٌ بالدقائق (الافتراضيّ: معايرةُ الخادم)")
	if err := fs.Parse(args); err != nil {
		return err
	}
	dir, err := stateDir(*dirFlag)
	if err != nil {
		return err
	}

	loop, err := newAgentLoop(dir)
	if err != nil {
		return err
	}
	fmt.Println("حلقةُ البروتوكول تعمل — device_id:", loop.state.DeviceID)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()
	err = loop.run(ctx, *once, time.Duration(*intervalMin)*time.Minute)
	if err != nil && errors.Is(err, ctx.Err()) {
		return nil // إيقافٌ نظيف بإشارة المستخدم
	}
	return err
}

func cmdUpdate(args []string) error {
	fs := flag.NewFlagSet("update", flag.ExitOnError)
	manifestURL := fs.String("manifest", "", "عنوانُ بيان التحديث {url, sha256}")
	target := fs.String("target", "", "مسارُ الثنائيّة المستبدَلة (الافتراضيّ: الثنائيّةُ الحالية)")
	if err := fs.Parse(args); err != nil {
		return err
	}
	if *manifestURL == "" {
		return errors.New("‏--manifest مطلوب")
	}
	if *target == "" {
		exe, err := os.Executable()
		if err != nil {
			return fmt.Errorf("تعذّر تحديد الثنائيّة الحالية: %w", err)
		}
		*target = exe
	}

	httpc := &http.Client{Timeout: 5 * time.Minute}
	m, err := update.FetchManifest(context.Background(), httpc, *manifestURL)
	if err != nil {
		return err
	}
	if err := update.Apply(context.Background(), httpc, m, *target); err != nil {
		return err
	}
	fmt.Println("بُدّلت الثنائيّةُ بعد التحقق من sha256 — النسخةُ الجديدة تعمل عند إعادة التشغيل.")
	return nil
}
