//go:build linux

package usb

// sysRoot — شجرةُ سمات USB التي يكتبها النواة.
const sysRoot = "/sys/bus/usb/devices"

// Snapshot — لقطةُ أجهزة هذه الآلة من sysfs.
func Snapshot() (map[string]Device, error) { return SnapshotDir(sysRoot) }
