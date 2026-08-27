Add-Type -AssemblyName System.Windows.Forms
Add-Type @'
using System;
using System.Runtime.InteropServices;
public static class MirrorPcInput {
  [DllImport("user32.dll")] public static extern bool SetCursorPos(int x, int y);
  [DllImport("user32.dll")] public static extern void mouse_event(uint flags, uint dx, uint dy, int data, UIntPtr extra);
  [DllImport("user32.dll")] public static extern void keybd_event(byte vk, byte scan, uint flags, UIntPtr extra);
  [DllImport("user32.dll")] public static extern int GetSystemMetrics(int index);
}
'@

$mouseFlags = @{ left = @(0x0002,0x0004); right = @(0x0008,0x0010); middle = @(0x0020,0x0040) }
$specialKeys = @{ Enter=0x0D; Escape=0x1B; Backspace=0x08; Tab=0x09; Space=0x20; ArrowLeft=0x25; ArrowUp=0x26; ArrowRight=0x27; ArrowDown=0x28; Delete=0x2E; Home=0x24; End=0x23; PageUp=0x21; PageDown=0x22 }

while (($line = [Console]::In.ReadLine()) -ne $null) {
  try {
    $message = $line | ConvertFrom-Json
    if ($message.type -eq 'move') {
      $width = [MirrorPcInput]::GetSystemMetrics(0); $height = [MirrorPcInput]::GetSystemMetrics(1)
      $x = [Math]::Max(0, [Math]::Min($width - 1, [int]([double]$message.x * $width)))
      $y = [Math]::Max(0, [Math]::Min($height - 1, [int]([double]$message.y * $height)))
      [void][MirrorPcInput]::SetCursorPos($x, $y)
    } elseif ($message.type -eq 'button' -and $mouseFlags.ContainsKey([string]$message.button)) {
      $pair = $mouseFlags[[string]$message.button]; $flag = if ($message.state -eq 'down') { $pair[0] } else { $pair[1] }
      [MirrorPcInput]::mouse_event($flag, 0, 0, 0, [UIntPtr]::Zero)
    } elseif ($message.type -eq 'wheel') {
      [MirrorPcInput]::mouse_event(0x0800, 0, 0, [int](-[double]$message.delta), [UIntPtr]::Zero)
    } elseif ($message.type -eq 'key') {
      $code = [string]$message.code; $vk = 0
      if ($specialKeys.ContainsKey($code)) { $vk = $specialKeys[$code] }
      elseif ($code -match '^Key([A-Z])$') { $vk = [int][char]$Matches[1] }
      elseif ($code -match '^Digit([0-9])$') { $vk = [int][char]$Matches[1] }
      if ($vk -gt 0) { [MirrorPcInput]::keybd_event([byte]$vk, 0, $(if ($message.state -eq 'up') { 2 } else { 0 }), [UIntPtr]::Zero) }
    }
  } catch { }
}
