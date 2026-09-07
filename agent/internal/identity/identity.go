// Package identity — هويّةُ الجهاز التعمويّة (الطور K · WP-K.1 · §45).
//
// زوجُ مفاتيح ECDSA على منحنى P-256 **يُولَّد على الجهاز ويبقى عليه**:
// المفتاحُ الخاصُّ يُخزَّن محليّاً PEM بصلاحيات 0600 في مجلد إعدادات المستخدم
// (`os.UserConfigDir()/lynomia-agent/`) — والموضعُ الوحيد في شجرة الوكيل كلِّها
// الذي يسلسله هو `storeLocal` أدناه ووجهتُه ملفٌ محليّ لا سلكٌ. لا دالةَ تُصدِّر
// مادتَه، ولا نوعَ يطبع سرَّه — «المفتاحُ الخاصُّ لا يغادر الجهاز» (عقدُ التوقيع
// في docblock ‏`App\Support\Es256` البند ٤، مرآةً حرفية).
//
// العامُّ وحدَه يُصدَّر: PEM على DER SubjectPublicKeyInfo (ما يقبله
// `Es256::isP256PublicKey`)، والبصمةُ sha256 hex على بايتات DER — مرآةُ
// `Es256::fingerprint` بالحرف. والتوقيعُ ES256: sha256 على البايتات ثم
// ECDSA DER ثم base64 — ما يفكّه وسيطُ `EndpointSignature`.
package identity

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

const keyFileName = "key.pem"

// Identity — هويّةُ الجهاز: المفتاحُ الخاصُّ P-256 غيرُ مُصدَّرٍ عمداً (حقلٌ
// خاصٌّ بلا getter) — فلا مسارَ تسلسلٍ سلكيّ له في أي حزمةٍ أخرى.
type Identity struct {
	priv *ecdsa.PrivateKey
}

// DefaultDir — مجلدُ الهويّة القياسيّ: os.UserConfigDir()/lynomia-agent
func DefaultDir() (string, error) {
	base, err := os.UserConfigDir()
	if err != nil {
		return "", fmt.Errorf("تعذّر تحديد مجلد إعدادات المستخدم: %w", err)
	}
	return filepath.Join(base, "lynomia-agent"), nil
}

// Generate — يولّد زوجَ P-256 **على الجهاز** ويخزّنه محليّاً 0600 ثم يعيد الهويّة.
func Generate(dir string) (*Identity, error) {
	priv, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, fmt.Errorf("توليدُ مفتاح P-256: %w", err)
	}
	id := &Identity{priv: priv}
	if err := id.storeLocal(dir); err != nil {
		return nil, err
	}
	return id, nil
}

// storeLocal — **الموضعُ الوحيد** في الوكيل الذي يسلسل المفتاحَ الخاصّ، ووجهتُه
// ملفُ `key.pem` المحليّ بصلاحيات 0600 حصراً (والمجلدُ 0700) — لا شبكةَ هنا.
func (id *Identity) storeLocal(dir string) error {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("إنشاءُ مجلد الهويّة: %w", err)
	}
	der, err := x509.MarshalECPrivateKey(id.priv)
	if err != nil {
		return fmt.Errorf("تسلسلُ المفتاح للمخزن المحليّ: %w", err)
	}
	blob := pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: der})
	if err := os.WriteFile(filepath.Join(dir, keyFileName), blob, 0o600); err != nil {
		return fmt.Errorf("كتابةُ ملف المفتاح: %w", err)
	}
	return nil
}

// Load — يحمّل الهويّة المخزَّنة. صلاحياتٌ متراخية (قراءةٌ لغير المالك) تُرفَض —
// مفتاحٌ مكشوفٌ لغير مالكه عطلٌ يُبلَّغ لا يُسكَت عنه.
func Load(dir string) (*Identity, error) {
	path := filepath.Join(dir, keyFileName)
	info, err := os.Stat(path)
	if err != nil {
		return nil, err
	}
	if info.Mode().Perm()&0o077 != 0 {
		return nil, fmt.Errorf("صلاحياتُ %s متراخية (%o) — المطلوب 0600؛ صحّحها ثم أعد المحاولة",
			path, info.Mode().Perm())
	}
	blob, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	block, _ := pem.Decode(blob)
	if block == nil || block.Type != "EC PRIVATE KEY" {
		return nil, errors.New("ملفُ المفتاح ليس PEM من نوع EC PRIVATE KEY")
	}
	priv, err := x509.ParseECPrivateKey(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("تحليلُ المفتاح المخزَّن: %w", err)
	}
	if priv.Curve != elliptic.P256() {
		return nil, errors.New("المفتاحُ المخزَّن ليس على منحنى P-256 — عقدُ Es256 لا يقبل سواه")
	}
	return &Identity{priv: priv}, nil
}

// LoadOrGenerate — يحمّل الهويّة القائمة أو يولّد واحدةً إن لم توجد بعد.
func LoadOrGenerate(dir string) (*Identity, error) {
	if _, err := os.Stat(filepath.Join(dir, keyFileName)); err == nil {
		return Load(dir)
	} else if !errors.Is(err, os.ErrNotExist) {
		return nil, err
	}
	return Generate(dir)
}

// PublicPEM — المفتاحُ العامّ PEM على DER SubjectPublicKeyInfo القياسيّ — الصيغةُ
// الوحيدة التي تسافر إلى الخادم عند التسجيل (`Es256::isP256PublicKey` يقبلها).
func (id *Identity) PublicPEM() (string, error) {
	der, err := x509.MarshalPKIXPublicKey(&id.priv.PublicKey)
	if err != nil {
		return "", fmt.Errorf("تسلسلُ المفتاح العامّ: %w", err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "PUBLIC KEY", Bytes: der})), nil
}

// Fingerprint — بصمةُ هذه الهويّة: sha256 hex على DER — مرآةُ Es256::fingerprint.
func (id *Identity) Fingerprint() (string, error) {
	pubPEM, err := id.PublicPEM()
	if err != nil {
		return "", err
	}
	return FingerprintPEM(pubPEM)
}

// FingerprintPEM — sha256 hex على بايتات DER لمفتاحٍ عامّ PEM — تثبت البصمةُ
// مهما اختلف التغليفُ بين مُرسِلَين (مرآةُ `Es256::fingerprint` حرفياً).
func FingerprintPEM(publicPEM string) (string, error) {
	block, _ := pem.Decode([]byte(publicPEM))
	if block == nil || block.Type != "PUBLIC KEY" {
		return "", errors.New("تعذّر استخراج DER من المفتاح العامّ")
	}
	sum := sha256.Sum256(block.Bytes)
	return hex.EncodeToString(sum[:]), nil
}

// Sign — توقيعُ ES256 على البايتات: sha256 ثم ECDSA (DER) ثم base64 قياسيّ —
// ما يسافر في `X-Endpoint-Signature` ويفكّه الخادمُ بـ`Es256::verify`.
func (id *Identity) Sign(data []byte) (string, error) {
	digest := sha256.Sum256(data)
	der, err := ecdsa.SignASN1(rand.Reader, id.priv, digest[:])
	if err != nil {
		return "", fmt.Errorf("توقيعُ ES256: %w", err)
	}
	return base64.StdEncoding.EncodeToString(der), nil
}
