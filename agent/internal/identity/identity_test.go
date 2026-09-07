package identity

// اختباراتُ الهويّة — تُكتب أولاً وتفشل أولاً (اصطلاح المستودع «إثبات لا ادّعاء»):
// البصمةُ تُطابَق ضدّ متّجهٍ حُسب مستقلاً بـopenssl **وتحقّق منه الخادمُ الحيّ**
// (App\Support\Es256::fingerprint أعاد القيمةَ نفسَها حرفاً حرفاً)، والمفتاحُ
// الخاصُّ يُثبَت أنه لا يظهر في أي مُخرَجٍ سلكيّ.

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/pem"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// متّجهُ البصمة المستقلّ: زوجٌ وُلّد بـopenssl، وsha256 على DER (SubjectPublicKeyInfo)
// حُسبت بـ`openssl ec -pubout -outform DER | sha256sum` — وطُوبقت ضدّ
// Es256::fingerprint في الخادم الحيّ قبل تثبيتها هنا.
const vectorPublicPEM = `-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEfUWxKuLFXBv93ONZmInGkNwvel0+
HfSb0TWyCNfM3LLBusMM/oQGjEhapXKgJYkmPBumS3/aBsPWmBzJotfqdQ==
-----END PUBLIC KEY-----
`

const vectorFingerprint = "86e33f6ab7ece2cec24b436947e9f7b32ee92006d5711146f1046b162b51d0a1"

func TestFingerprintPEMMatchesServerVector(t *testing.T) {
	got, err := FingerprintPEM(vectorPublicPEM)
	if err != nil {
		t.Fatalf("FingerprintPEM: %v", err)
	}
	if got != vectorFingerprint {
		t.Fatalf("البصمةُ لا تطابق متّجهَ الخادم:\n got=%s\nwant=%s", got, vectorFingerprint)
	}
}

func TestGenerateStoresKey0600AndReloadStable(t *testing.T) {
	dir := t.TempDir()
	id, err := Generate(dir)
	if err != nil {
		t.Fatalf("Generate: %v", err)
	}

	keyPath := filepath.Join(dir, "key.pem")
	info, err := os.Stat(keyPath)
	if err != nil {
		t.Fatalf("ملفُ المفتاح غيرُ مكتوب: %v", err)
	}
	if perm := info.Mode().Perm(); perm != 0o600 {
		t.Fatalf("صلاحياتُ ملف المفتاح %o — العقدُ 0600", perm)
	}

	// إعادةُ التحميل مستقرّة: البصمةُ والمفتاحُ العامّ لا يتبدّلان
	again, err := Load(dir)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	fp1, err := id.Fingerprint()
	if err != nil {
		t.Fatalf("Fingerprint: %v", err)
	}
	fp2, err := again.Fingerprint()
	if err != nil {
		t.Fatalf("Fingerprint (بعد التحميل): %v", err)
	}
	if fp1 != fp2 {
		t.Fatalf("البصمةُ تبدّلت بعد إعادة التحميل: %s ≠ %s", fp1, fp2)
	}
	p1, _ := id.PublicPEM()
	p2, _ := again.PublicPEM()
	if p1 != p2 {
		t.Fatal("المفتاحُ العامّ تبدّل بعد إعادة التحميل")
	}
}

func TestLoadOrGenerateIsIdempotent(t *testing.T) {
	dir := t.TempDir()
	a, err := LoadOrGenerate(dir)
	if err != nil {
		t.Fatalf("LoadOrGenerate (أولاً): %v", err)
	}
	b, err := LoadOrGenerate(dir)
	if err != nil {
		t.Fatalf("LoadOrGenerate (ثانياً): %v", err)
	}
	fa, _ := a.Fingerprint()
	fb, _ := b.Fingerprint()
	if fa != fb {
		t.Fatalf("LoadOrGenerate ولّد مفتاحاً ثانياً: %s ≠ %s", fa, fb)
	}
}

func TestLoadRefusesLoosePermissions(t *testing.T) {
	dir := t.TempDir()
	if _, err := Generate(dir); err != nil {
		t.Fatalf("Generate: %v", err)
	}
	if err := os.Chmod(filepath.Join(dir, "key.pem"), 0o644); err != nil {
		t.Fatalf("Chmod: %v", err)
	}
	if _, err := Load(dir); err == nil {
		t.Fatal("مفتاحٌ بصلاحياتٍ متراخية (0644) قُبل — يجب الرفض")
	}
}

func TestFingerprintMatchesIndependentSha256OverDER(t *testing.T) {
	dir := t.TempDir()
	id, err := Generate(dir)
	if err != nil {
		t.Fatalf("Generate: %v", err)
	}
	pubPEM, err := id.PublicPEM()
	if err != nil {
		t.Fatalf("PublicPEM: %v", err)
	}

	// الحسابُ المستقلّ: فكُّ PEM إلى DER ثم sha256 hex — مرآةُ Es256::fingerprint
	block, _ := pem.Decode([]byte(pubPEM))
	if block == nil || block.Type != "PUBLIC KEY" {
		t.Fatal("PublicPEM ليس PEM من نوع PUBLIC KEY")
	}
	sum := sha256.Sum256(block.Bytes)
	want := hex.EncodeToString(sum[:])

	got, err := id.Fingerprint()
	if err != nil {
		t.Fatalf("Fingerprint: %v", err)
	}
	if got != want {
		t.Fatalf("البصمةُ ليست sha256(DER):\n got=%s\nwant=%s", got, want)
	}
}

func TestSignVerifyRoundTripAndTamperDetection(t *testing.T) {
	dir := t.TempDir()
	id, err := Generate(dir)
	if err != nil {
		t.Fatalf("Generate: %v", err)
	}
	pubPEM, _ := id.PublicPEM()
	block, _ := pem.Decode([]byte(pubPEM))
	parsed, err := x509.ParsePKIXPublicKey(block.Bytes)
	if err != nil {
		t.Fatalf("ParsePKIXPublicKey: %v", err)
	}
	pub, ok := parsed.(*ecdsa.PublicKey)
	if !ok || pub.Curve != elliptic.P256() {
		t.Fatal("المفتاحُ العامّ ليس ECDSA P-256")
	}

	data := []byte("POST\n/api/v1/endpoint/heartbeat\n1700000000\nabcdefgh\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855")
	sigB64, err := id.Sign(data)
	if err != nil {
		t.Fatalf("Sign: %v", err)
	}
	der, err := base64.StdEncoding.DecodeString(sigB64)
	if err != nil {
		t.Fatalf("التوقيعُ ليس base64 قياسياً: %v", err)
	}

	digest := sha256.Sum256(data)
	if !ecdsa.VerifyASN1(pub, digest[:], der) {
		t.Fatal("توقيعٌ صحيحٌ لم يُتحقَّق — الدورةُ sign→verify مكسورة")
	}

	// عبثٌ بكل جزءٍ من السلسلة القانونية → التحقق يفشل (التوقيعُ يقيّد الكل)
	for name, tampered := range map[string][]byte{
		"body-hash": []byte("POST\n/api/v1/endpoint/heartbeat\n1700000000\nabcdefgh\nf3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"),
		"timestamp": []byte("POST\n/api/v1/endpoint/heartbeat\n1700000001\nabcdefgh\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"),
		"nonce":     []byte("POST\n/api/v1/endpoint/heartbeat\n1700000000\nabcdefgi\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"),
	} {
		d := sha256.Sum256(tampered)
		if ecdsa.VerifyASN1(pub, d[:], der) {
			t.Fatalf("عبثٌ بـ%s مرّ بالتحقق — كسرٌ في ربط التوقيع", name)
		}
	}
}

func TestNoPrivateMaterialInWireOutputs(t *testing.T) {
	dir := t.TempDir()
	id, err := Generate(dir)
	if err != nil {
		t.Fatalf("Generate: %v", err)
	}
	pubPEM, err := id.PublicPEM()
	if err != nil {
		t.Fatalf("PublicPEM: %v", err)
	}
	if strings.Contains(pubPEM, "PRIVATE") {
		t.Fatal("PublicPEM يحمل مادةَ مفتاحٍ خاصّ — ممنوعٌ قطعاً")
	}
	fp, _ := id.Fingerprint()
	if strings.Contains(fp, "PRIVATE") || len(fp) != 64 {
		t.Fatalf("بصمةٌ خارج الشكل sha256 hex: %q", fp)
	}
}
