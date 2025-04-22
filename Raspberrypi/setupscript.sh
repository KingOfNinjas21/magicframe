#!/bin/bash

# Define the autostart directory and file path
AUTOSTART_DIR="/home/kingu/.config/autostart"
DESKTOP_FILE="$AUTOSTART_DIR/magicframe.desktop"

# Ensure the autostart directory exists
sudo mkdir -p "$AUTOSTART_DIR"

# Content for the desktop file with git pull included before the loginscript execution
DESKTOP_CONTENT="[Desktop Entry]
Type=Application
Name=MagicFrame
Exec=bash -c 'for i in {1..10}; do ping -c1 github.com && break || sleep 1; done; cd /home/kingu/Documents/loginscript/magicframe && git pull || true; /usr/bin/python3 /home/kingu/Documents/loginscript/magicframe/Raspberrypi/loginscript.py'
"

# Write the content to the desktop file
echo "$DESKTOP_CONTENT" > "$DESKTOP_FILE"

# Make the file executable
sudo chmod +x "$DESKTOP_FILE"

echo "MagicFrame autostart configuration has been set up at $DESKTOP_FILE"

# Display rotation on start
# Was unable to do it via the config file, so i wam using this way
DISPLAY_ROTATION_FILE="/etc/xdg/autostart/rotate-display.desktop"
DISPLAY_ROTATION_CONTENT="[Desktop Entry]
Type=Application
Name=Rotate Display
Exec=sh -c "xrandr --output HDMI-1 --rotate inverted"
NoDisplay=false
X-GNOME-Autostart-enabled=true
"
# Write the content to the desktop file
echo "$DISPLAY_ROTATION_CONTENT" > "$DISPLAY_ROTATION_FILE"

# Make the file executable
sudo chmod +x "$DISPLAY_ROTATION_FILE"

echo "MagicFrame display rotation has been set up at $DISPLAY_ROTATION_FILE"

# Create or overwrite /boot/config.txt
CONFIG_FILE="/boot/config.txt"
CONFIG_CONTENT="# For more options and information see
# http://rpf.io/configtxt
# Some settings may impact device functionality. See link above for details
# uncomment if you get no picture on HDMI for a default \"safe\" mode
#hdmi_safe=1
# uncomment the following to adjust overscan. Use positive numbers if console
# goes off screen, and negative if there is too much border
#overscan_left=16
#overscan_right=16
#overscan_top=16
#overscan_bottom=16
# uncomment to force a console size. By default it will be display's size minus
# overscan.
#framebuffer_width=1280
#framebuffer_height=720
# uncomment if hdmi display is not detected and composite is being output
#hdmi_force_hotplug=1
# uncomment to force a specific HDMI mode (this will force VGA)
#hdmi_group=1
#hdmi_mode=1
# uncomment to force a HDMI mode rather than DVI. This can make audio work in
# DMT (computer monitor) modes
#hdmi_drive=2
# uncomment to increase signal to HDMI, if you have interference, blanking, or
# no display
#config_hdmi_boost=4
# uncomment for composite PAL
#sdtv_mode=2
#uncomment to overclock the arm. 700 MHz is the default.
#arm_freq=800
# Uncomment some or all of these to enable the optional hardware interfaces
#dtparam=i2c_arm=on
#dtparam=i2s=on
#dtparam=spi=on
# Uncomment this to enable infrared communication.
#dtoverlay=gpio-ir,gpio_pin=17
#dtoverlay=gpio-ir-tx,gpio_pin=18
# Additional overlays and parameters are documented /boot/overlays/README
# Enable audio (loads snd_bcm2835)
dtparam=audio=on
# Automatically load overlays for detected cameras
camera_auto_detect=1
# Automatically load overlays for detected DSI displays
display_auto_detect=1
# Enable DRM VC4 V3D driver
dtoverlay=vc4-kms-v3d
max_framebuffers=2
# Disable compensation for displays with overscan
disable_overscan=1
[cm4]
# Enable host mode on the 2711 built-in XHCI USB controller.
# This line should be removed if the legacy DWC2 controller is required
# (e.g. for USB device mode) or if USB support is not required.
otg_mode=1
[all]
[pi4]
# Run as fast as firmware / board allows
arm_boost=1
[all]
find ~/ -type f -name \"postgis-2.0.0\"
"

# Write the content to the config file (requires sudo)
echo "Creating or overwriting $CONFIG_FILE (will prompt for sudo password)"
echo "$CONFIG_CONTENT" | sudo tee "$CONFIG_FILE" > /dev/null

echo "Config file has been updated at $CONFIG_FILE"

sudo reboot