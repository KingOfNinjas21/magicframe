#!/usr/bin/env python3
import os
import sys
import time
import random
import json
import requests
import threading
import subprocess
from datetime import datetime
import tkinter as tk
from tkinter import ttk, simpledialog
from PIL import Image, ImageTk
import pygame

import tempfile
import zipfile
import shutil
import re
import mimetypes
import time

# Server API configuration
SERVER_BASE_URL = "http://212.132.64.123/magicframe/website/"
API_URL = f"{SERVER_BASE_URL}/api.php"

# Local settings                                                                
IMAGE_DIR = os.path.expanduser("~/slideshow_images")
CONFIG_FILE = os.path.expanduser("~/magicframe_config.json")
SUPPORTED_FORMATS = ['.jpg', '.jpeg', '.png', '.gif']
SLIDESHOW_DELAY = 10  # seconds between images
CHECK_NEW_IMAGES_INTERVAL = 300  # check for new images every 5 minutes

# Ensure image directory exists
os.makedirs(IMAGE_DIR, exist_ok=True)

class OnScreenKeyboard:
    def __init__(self, master=None):
        self.master = master
        self.keyboard_window = None
        self.entry_widget = None
        self.callback = None
        
    def show_keyboard(self, entry_widget, callback=None):
        self.entry_widget = entry_widget
        self.callback = callback
        
        if self.keyboard_window:
            self.keyboard_window.destroy()
            
        self.keyboard_window = tk.Toplevel(self.master)
        self.keyboard_window.title("On-Screen Keyboard")
        self.keyboard_window.attributes('-fullscreen', False)
        
        # Define the keyboard layout
        keys = [
            ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', 'Backspace'],
            ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
            ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l', '@'],
            ['z', 'x', 'c', 'v', 'b', 'n', 'm', '.', '_', '-'],
            ['Space', 'Enter']
        ]
        
        # Create keyboard buttons
        for i, row in enumerate(keys):
            frame = tk.Frame(self.keyboard_window)
            frame.pack(fill='x')
            
            for key in row:
                if key == 'Space':
                    button = tk.Button(frame, text=key, width=20, command=lambda k=' ': self.press_key(k))
                elif key == 'Backspace':
                    button = tk.Button(frame, text=key, width=10, command=self.backspace)
                elif key == 'Enter':
                    button = tk.Button(frame, text=key, width=15, bg='#90EE90', command=self.enter)
                else:
                    button = tk.Button(frame, text=key, width=5, command=lambda k=key: self.press_key(k))
                button.pack(side='left', padx=2, pady=2)
    
    def press_key(self, key):
        if self.entry_widget:
            current_text = self.entry_widget.get()
            cursor_position = self.entry_widget.index(tk.INSERT)
            new_text = current_text[:cursor_position] + key + current_text[cursor_position:]
            self.entry_widget.delete(0, tk.END)
            self.entry_widget.insert(0, new_text)
            self.entry_widget.icursor(cursor_position + 1)
    
    def backspace(self):
        if self.entry_widget:
            current_text = self.entry_widget.get()
            cursor_position = self.entry_widget.index(tk.INSERT)
            if cursor_position > 0:
                new_text = current_text[:cursor_position-1] + current_text[cursor_position:]
                self.entry_widget.delete(0, tk.END)
                self.entry_widget.insert(0, new_text)
                self.entry_widget.icursor(cursor_position - 1)
    
    def enter(self):
        if self.callback:
            self.callback()
        self.hide_keyboard()
    
    def hide_keyboard(self):
        if self.keyboard_window:
            self.keyboard_window.destroy()
            self.keyboard_window = None

class MagicFrameApp:
    def __init__(self, root):
        self.root = root
        self.root.title("Magic Photo Frame")
        self.root.attributes('-fullscreen', True)
        
        # Initialize variables
        self.token = None
        self.user_id = None
        self.username = None
        self.is_online_mode = False
        self.slideshow_running = False
        self.slideshow_thread = None
        self.images = []
        self.current_image_index = 0
        self.check_new_images_thread = None
        
        # Load saved config if exists
        self.load_config()
        
        # Create UI
        self.setup_ui()
        
    def load_config(self):
        if os.path.exists(CONFIG_FILE):
            try:
                with open(CONFIG_FILE, 'r') as f:
                    config = json.load(f)
                    self.token = config.get('token')
                    self.user_id = config.get('user_id')
                    self.username = config.get('username')
            except Exception as e:
                print(f"Error loading config: {e}")
                
    def save_config(self):
        config = {
            'token': self.token,
            'user_id': self.user_id,
            'username': self.username
        }
        try:
            with open(CONFIG_FILE, 'w') as f:
                json.dump(config, f)
        except Exception as e:
            print(f"Error saving config: {e}")
    
    def setup_ui(self):
        # Main frame
        self.main_frame = tk.Frame(self.root)
        self.main_frame.pack(fill=tk.BOTH, expand=True)
        
        # Title and logo
        self.title_label = tk.Label(self.main_frame, text="Magic Photo Frame", font=("Helvetica", 24, "bold"))
        self.title_label.pack(pady=20)
        
        # Mode selection frame
        self.mode_frame = tk.Frame(self.main_frame)
        self.mode_frame.pack(pady=20)
        
        # Online mode button
        self.online_btn = tk.Button(self.mode_frame, text="Online Mode", width=15, height=2, 
                                   command=self.start_online_mode)
        self.online_btn.pack(side=tk.LEFT, padx=10)
        
        # Offline mode button
        self.offline_btn = tk.Button(self.mode_frame, text="Offline Mode", width=15, height=2,
                                    command=self.start_offline_mode)
        self.offline_btn.pack(side=tk.LEFT, padx=10)
        
        # Wi-Fi setup button
        self.wifi_btn = tk.Button(self.main_frame, text="Wi-Fi Settings", width=15, height=2,
                                 command=self.setup_wifi)
        self.wifi_btn.pack(pady=10)

         # Login button
        logout_btn = tk.Button(self.main_frame, text="Logout", width=15, command=self.logout)
        logout_btn.pack(pady=20)
        
        # On-screen keyboard
        self.keyboard = OnScreenKeyboard(self.root)
        
    def start_online_mode(self):
        self.is_online_mode = True
        
        # If already logged in, start slideshow directly
        if self.token:
            self.verify_token()
        else:
            self.show_login_screen()
    
    def start_offline_mode(self):
        self.is_online_mode = False
        self.load_local_images()
        if self.images:
            self.start_slideshow()
        else:
            self.show_message("No Images", "No images found locally. Please download images first.")
    
    def show_login_screen(self):
        # Hide main frame
        self.main_frame.pack_forget()

        # Create login frame
        self.login_frame = tk.Frame(self.root)
        self.login_frame.pack(fill=tk.BOTH, expand=True)

        # Track if keyboard was opened
        self.username_keyboard_shown = False
        self.password_keyboard_shown = False

        # Login title
        login_title = tk.Label(self.login_frame, text="Login", font=("Helvetica", 20, "bold"))
        login_title.pack(pady=20)

        # Username field
        username_frame = tk.Frame(self.login_frame)
        username_frame.pack(pady=10)

        username_label = tk.Label(username_frame, text="Username:", width=10, anchor='w')
        username_label.pack(side=tk.LEFT, padx=5)

        self.username_entry = tk.Entry(username_frame, width=20)
        self.username_entry.pack(side=tk.LEFT, padx=5)

        def on_username_focus(event):
            if not self.username_keyboard_shown:
                self.keyboard.show_keyboard(self.username_entry)
                self.username_keyboard_shown = True

        self.username_entry.bind("<FocusIn>", on_username_focus)

        username_keyboard_btn = tk.Button(
            username_frame,
            text="🖮",
            command=lambda: self.keyboard.show_keyboard(self.username_entry)
        )
        username_keyboard_btn.pack(side=tk.LEFT, padx=5)

        # Password field
        password_frame = tk.Frame(self.login_frame)
        password_frame.pack(pady=10)

        password_label = tk.Label(password_frame, text="Password:", width=10, anchor='w')
        password_label.pack(side=tk.LEFT, padx=5)

        self.password_entry = tk.Entry(password_frame, width=20, show="*")
        self.password_entry.pack(side=tk.LEFT, padx=5)

        def on_password_focus(event):
            if not self.password_keyboard_shown:
                self.keyboard.show_keyboard(self.password_entry)
                self.password_keyboard_shown = True

        self.password_entry.bind("<FocusIn>", on_password_focus)

        password_keyboard_btn = tk.Button(
            password_frame,
            text="🖮",
            command=lambda: self.keyboard.show_keyboard(self.password_entry)
        )
        password_keyboard_btn.pack(side=tk.LEFT, padx=5)

        # Login button
        login_btn = tk.Button(self.login_frame, text="Login", width=15, command=self.login)
        login_btn.pack(pady=20)

        # Back button
        back_btn = tk.Button(self.login_frame, text="Back", width=10, command=self.back_to_main)
        back_btn.pack(pady=10)


    
    def logout(self):
        if not self.token:
            # Already logged out
            self.show_message("Info", "You are not currently logged in")
            return
        
        try:
            # Call server logout endpoint
            headers = {"Authorization": f"Bearer {self.token}"}
            response = requests.post(
                f"{API_URL}/logout",
                headers=headers
            )
            
            if response.status_code == 200:
                # Server-side logout successful
                data = response.json()
                if data.get('success'):
                    # Clear local authentication data
                    self.token = None
                    self.user_id = None
                    self.username = None
                    self.save_config()
                    
                    # Stop any running slideshow
                    if hasattr(self, 'slideshow_running') and self.slideshow_running:
                        self.stop_slideshow()
                    
                    # Hide active frames
                    if hasattr(self, 'slideshow_frame') and self.slideshow_frame:
                        self.slideshow_frame.pack_forget()
                    
                    # Show login screen
                    self.show_login_screen()
                    
                    # Show success message
                    self.show_message("Logged Out", data.get('message', 'You have been successfully logged out'))
                else:
                    self.show_message("Error", data.get('message', 'Unknown error during logout'))
            else:
                error_msg = "Unknown error"
                try:
                    error_data = response.json()
                    error_msg = error_data.get('message', error_data.get('error', 'Unknown error'))
                except:
                    pass
                self.show_message("Logout Failed", error_msg)
        except Exception as e:
            self.show_message("Connection Error", f"Failed to connect to server: {str(e)}")
            # If server connection fails, still logout locally
            self.token = None
            self.user_id = None
            self.username = None
            self.save_config()
            self.show_login_screen()
            self.show_message("Partial Logout", "Logged out locally, but server connection failed")

    def login(self):
        username = self.username_entry.get()
        password = self.password_entry.get()
        
        if not username or not password:
            self.show_message("Error", "Please enter username and password")
            return
        
        try:
            response = requests.post(
                f"{API_URL}/login", 
                json={"username": username, "password": password}
            )
            
            if response.status_code == 200:
                data = response.json()
                if "token" in data:
                    self.token = data["token"]
                    self.user_id = data["user_id"]
                    self.username = data["username"]
                    self.save_config()
                    
                    # Go to slideshow
                    self.login_frame.pack_forget()
                    self.load_local_images()
                    self.check_for_new_images()
                    self.start_slideshow()
                else:
                    self.show_message("Error", "Invalid response from server")
            else:
                error_msg = "Unknown error"
                try:
                    error_data = response.json()
                    error_msg = error_data.get("error", "Unknown error")
                except:
                    pass
                self.show_message("Login Failed", error_msg)
        except Exception as e:
            self.show_message("Connection Error", f"Failed to connect to server: {str(e)}")
    
    def verify_token(self):
        try:
            headers = {"Authorization": f"Bearer {self.token}"}
            response = requests.get(f"{API_URL}/images", headers=headers)
            
            if response.status_code == 200:
                # Token is valid, proceed with slideshow
                self.load_local_images()
                self.check_for_new_images()
                self.start_slideshow()
            else:
                # Invalid token, show login screen
                self.token = None
                self.user_id = None
                self.username = None
                self.save_config()
                self.show_login_screen()
        except Exception as e:
            self.show_message("Connection Error", f"Failed to connect to server: {str(e)}")
            # If connection fails but we're in online mode, still try to start slideshow with local images
            self.load_local_images()
            self.start_slideshow()
    
    def back_to_main(self):
        # Hide current frame
        if hasattr(self, 'login_frame') and self.login_frame.winfo_exists():
            self.login_frame.pack_forget()
        
        # Show main frame
        self.main_frame.pack(fill=tk.BOTH, expand=True)
    
    def load_local_images(self):
        self.images = []
        for filename in os.listdir(IMAGE_DIR):
            ext = os.path.splitext(filename)[1].lower()
            if ext in SUPPORTED_FORMATS:
                self.images.append(os.path.join(IMAGE_DIR, filename))
        
        # Randomize images order
        random.shuffle(self.images)
    
    def check_for_new_images(self):
        if not self.is_online_mode or not self.token:
            return
        
        try:
            headers = {"Authorization": f"Bearer {self.token}"}
            response = requests.get(f"{API_URL}/notDownloadedImages?download=true", headers=headers, stream=True)
            
            if response.status_code == 200:
                # Check content type to determine if we got JSON or files
                content_type = response.headers.get('Content-Type', '')
                
                if 'application/json' in content_type:
                    # Handle JSON response (no new images or keeping compatibility)
                    data = response.json()
                    
                    # Check if there's a message about no new images
                    if 'message' in data and 'no new images' in data['message'].lower():
                        print("No new images to download")
                    else:
                        # Process metadata and download images individually (old method)
                        images = data.get("images", [])
                        self._process_image_metadata(images)
                
                elif 'application/zip' in content_type:
                    # Handle ZIP file download
                    self._process_zip_download(response)
                
                elif any(img_type in content_type for img_type in ['image/jpeg', 'image/png', 'image/gif']):
                    # Handle single image download
                    self._process_single_image_download(response)
                
                else:
                    print(f"Unexpected content type: {content_type}")
                    
        except Exception as e:
            print(f"Error checking for new images: {str(e)}")
        
        # Schedule the next check
        if self.slideshow_running and self.is_online_mode:
            self.root.after(CHECK_NEW_IMAGES_INTERVAL * 1000, self.check_for_new_images)

    def _process_image_metadata(self, images):
        """Process image metadata and download images individually (original method)"""
        new_images_added = False
        
        for img in images:
            image_url = f"{SERVER_BASE_URL}/{img['url']}"
            # Set the local filename to the original filename if available
            local_filename = os.path.join(IMAGE_DIR, img['original_filename'] if 'original_filename' in img else os.path.basename(img['url']))
            
            try:
                # Download the image
                img_response = requests.get(image_url, stream=True)
                if img_response.status_code == 200:
                    with open(local_filename, 'wb') as f:
                        for chunk in img_response.iter_content(1024):
                            f.write(chunk)
                    
                    # Add the new image to the slideshow list
                    self.images.append(local_filename)
                    new_images_added = True
            except Exception as e:
                print(f"Error downloading image {image_url}: {str(e)}")
        
        # If we downloaded new images, reload and shuffle the list
        if new_images_added:
            self.load_local_images()

    def _process_zip_download(self, response):
        """Process a ZIP file containing multiple images"""
        try:
            # Create a temporary file to store the ZIP
            with tempfile.NamedTemporaryFile(delete=False, suffix='.zip') as temp_file:
                # Write the ZIP content to the temporary file
                for chunk in response.iter_content(chunk_size=8192):
                    temp_file.write(chunk)
                temp_file_path = temp_file.name
            
            # Process the ZIP file
            new_images_added = False
            with zipfile.ZipFile(temp_file_path, 'r') as zip_ref:
                # Extract all images
                for file_info in zip_ref.infolist():
                    if not file_info.is_dir():
                        # Get the filename without path
                        filename = os.path.basename(file_info.filename)
                        local_filename = os.path.join(IMAGE_DIR, filename)
                        
                        # Extract the file
                        with zip_ref.open(file_info) as source, open(local_filename, 'wb') as target:
                            shutil.copyfileobj(source, target)
                        
                        # Add to slideshow list
                        self.images.append(local_filename)
                        new_images_added = True
            
            # Delete the temporary ZIP file
            os.unlink(temp_file_path)
            
            # If we added new images, reload and shuffle the list
            if new_images_added:
                self.load_local_images()
                
        except Exception as e:
            print(f"Error processing ZIP download: {str(e)}")

    def _process_single_image_download(self, response):
        """Handle a single image download"""
        try:
            # Get filename from Content-Disposition header if available
            content_disposition = response.headers.get('Content-Disposition', '')
            filename = None
            
            if 'filename=' in content_disposition:
                # Extract filename from header
                filename = re.findall('filename="(.+)"', content_disposition)
                if filename:
                    filename = filename[0]
            
            if not filename:
                # Generate a filename based on timestamp if none is provided
                ext = mimetypes.guess_extension(response.headers.get('Content-Type', ''))
                filename = f"image_{int(time.time())}{ext or '.jpg'}"
            
            # Save the image
            local_filename = os.path.join(IMAGE_DIR, filename)
            with open(local_filename, 'wb') as f:
                for chunk in response.iter_content(chunk_size=1024):
                    f.write(chunk)
            
            # Add to slideshow and reload
            self.images.append(local_filename)
            self.load_local_images()
            
        except Exception as e:
            print(f"Error processing single image download: {str(e)}")
    
    def start_slideshow(self):
        if not self.images:
            self.show_message("No Images", "No images found. Please add images to the folder.")
            self.back_to_main()
            return
        
        # Hide all frames
        for widget in self.root.winfo_children():
            if isinstance(widget, tk.Toplevel):
                widget.withdraw()
            else:
                widget.pack_forget()
        
        # Create slideshow frame
        self.slideshow_frame = tk.Frame(self.root, bg='black')
        self.slideshow_frame.pack(fill=tk.BOTH, expand=True)
        
        # Image label
        self.image_label = tk.Label(self.slideshow_frame, bg='black')
        self.image_label.pack(fill=tk.BOTH, expand=True)
        
        # Bind click events to exit slideshow
        self.slideshow_frame.bind("<Button-1>", self.stop_slideshow)
        self.image_label.bind("<Button-1>", self.stop_slideshow)
        
        # Start slideshow
        self.slideshow_running = True
        self.current_image_index = 0
        self.show_next_image()
        
        # If in online mode, periodically check for new images
        if self.is_online_mode:
            self.root.after(CHECK_NEW_IMAGES_INTERVAL * 1000, self.check_for_new_images)
    
    def show_next_image(self):
        if not self.slideshow_running or not self.images:
            return
        
        try:
            # Get current image path
            image_path = self.images[self.current_image_index]
            
            # Open and resize image to fit screen
            image = Image.open(image_path)
            screen_width = self.root.winfo_screenwidth()
            screen_height = self.root.winfo_screenheight()
            
            # Calculate aspect ratio for resizing
            img_width, img_height = image.size
            aspect_ratio = img_width / img_height
            
            if screen_width / screen_height > aspect_ratio:
                # Screen is wider than image
                new_height = screen_height
                new_width = int(aspect_ratio * new_height)
            else:
                # Screen is taller than image
                new_width = screen_width
                new_height = int(new_width / aspect_ratio)
                
            image = image.resize((new_width, new_height), Image.LANCZOS)
            photo = ImageTk.PhotoImage(image)
            
            # Update image
            self.image_label.config(image=photo)
            self.image_label.image = photo  # Keep reference
            
            # Move to next image
            self.current_image_index = (self.current_image_index + 1) % len(self.images)
            
            # Schedule next image
            self.root.after(SLIDESHOW_DELAY * 1000, self.show_next_image)
        except Exception as e:
            print(f"Error showing image: {str(e)}")
            # Skip to next image
            self.current_image_index = (self.current_image_index + 1) % len(self.images)
            self.root.after(100, self.show_next_image)
    
    def stop_slideshow(self, event=None):
        self.slideshow_running = False
        if hasattr(self, 'slideshow_frame'):
            self.slideshow_frame.pack_forget()
        self.back_to_main()
    
    def setup_wifi(self):
        # Create WiFi setup screen
        wifi_window = tk.Toplevel(self.root)
        wifi_window.title("WiFi Setup")
        wifi_window.geometry("600x500")
        
        # Create frames
        top_frame = tk.Frame(wifi_window)
        top_frame.pack(fill='x', pady=10)
        
        list_frame = tk.Frame(wifi_window)
        list_frame.pack(fill='both', expand=True, padx=10, pady=10)
        
        bottom_frame = tk.Frame(wifi_window)
        bottom_frame.pack(fill='x', pady=10)
        
        # Title
        title_label = tk.Label(top_frame, text="WiFi Setup", font=("Helvetica", 16, "bold"))
        title_label.pack()
        
        # WiFi list
        wifi_listbox = tk.Listbox(list_frame, width=50, height=15)
        wifi_listbox.pack(side=tk.LEFT, fill='both', expand=True)
        
        scrollbar = tk.Scrollbar(list_frame)
        scrollbar.pack(side=tk.RIGHT, fill='y')
        
        wifi_listbox.config(yscrollcommand=scrollbar.set)
        scrollbar.config(command=wifi_listbox.yview)
        
        # Status label
        status_label = tk.Label(bottom_frame, text="")
        status_label.pack(pady=5)
        
        # Function to get WiFi networks
        def get_wifi_networks():
            wifi_listbox.delete(0, tk.END)
            status_label.config(text="Scanning for networks...")
            wifi_window.update()
            
            try:
                # Run command to get WiFi networks
                result = subprocess.run(['sudo', 'iwlist', 'wlan0', 'scan'], 
                                      capture_output=True, text=True)
                
                networks = []
                for line in result.stdout.split('\n'):
                    if "ESSID:" in line:
                        ssid = line.split('ESSID:"')[1].split('"')[0]
                        if ssid and ssid not in networks:
                            networks.append(ssid)
                
                for ssid in networks:
                    wifi_listbox.insert(tk.END, ssid)
                
                status_label.config(text=f"Found {len(networks)} networks")
            except Exception as e:
                status_label.config(text=f"Error scanning: {str(e)}")
        
        # Function to connect to WiFi
        def connect_to_wifi():
            selected_idx = wifi_listbox.curselection()
            if not selected_idx:
                status_label.config(text="Please select a network")
                return
            
            ssid = wifi_listbox.get(selected_idx[0])
            
            # Create password dialog
            pwd_dialog = tk.Toplevel(wifi_window)
            pwd_dialog.title(f"Connect to {ssid}")
            pwd_dialog.geometry("400x200")
            
            # Password entry
            pwd_frame = tk.Frame(pwd_dialog)
            pwd_frame.pack(pady=20)
            
            pwd_label = tk.Label(pwd_frame, text="Password:")
            pwd_label.pack(side=tk.LEFT, padx=5)
            
            pwd_entry = tk.Entry(pwd_frame, show="*", width=25)
            pwd_entry.pack(side=tk.LEFT, padx=5)
            
            keyboard_btn = tk.Button(pwd_frame, text="🖮", 
                                    command=lambda: OnScreenKeyboard(pwd_dialog).show_keyboard(pwd_entry))
            keyboard_btn.pack(side=tk.LEFT, padx=5)
            
            # Connect button
            def do_connect():
                password = pwd_entry.get()
                status_label.config(text=f"Connecting to {ssid}...")
                wifi_window.update()
                
                try:
                    # Write to wpa_supplicant.conf
                    wpa_config = (
                        'ctrl_interface=DIR=/var/run/wpa_supplicant GROUP=netdev\n'
                        'update_config=1\n'
                        'country=US\n\n'
                        'network={\n'
                        f'    ssid="{ssid}"\n'
                        f'    psk="{password}"\n'
                        '    key_mgmt=WPA-PSK\n'
                        '}\n'
                    )
                    
                    with open('/tmp/wpa_supplicant.conf', 'w') as f:
                        f.write(wpa_config)
                    
                    # Copy file to /etc/wpa_supplicant/
                    subprocess.run(['sudo', 'cp', '/tmp/wpa_supplicant.conf', '/etc/wpa_supplicant/wpa_supplicant.conf'])
                    
                    # Restart WiFi
                    subprocess.run(['sudo', 'wpa_cli', '-i', 'wlan0', 'reconfigure'])
                    
                    status_label.config(text=f"Connected to {ssid}")
                    pwd_dialog.destroy()
                except Exception as e:
                    status_label.config(text=f"Connection error: {str(e)}")
            
            connect_btn = tk.Button(pwd_dialog, text="Connect", width=15, command=do_connect)
            connect_btn.pack(pady=10)
            
            cancel_btn = tk.Button(pwd_dialog, text="Cancel", width=10, command=pwd_dialog.destroy)
            cancel_btn.pack(pady=5)
        
        # Buttons
        btn_frame = tk.Frame(bottom_frame)
        btn_frame.pack(pady=10)
        
        scan_btn = tk.Button(btn_frame, text="Scan Networks", width=15, command=get_wifi_networks)
        scan_btn.pack(side=tk.LEFT, padx=10)
        
        connect_btn = tk.Button(btn_frame, text="Connect", width=15, command=connect_to_wifi)
        connect_btn.pack(side=tk.LEFT, padx=10)
        
        close_btn = tk.Button(btn_frame, text="Close", width=10, command=wifi_window.destroy)
        close_btn.pack(side=tk.LEFT, padx=10)
        
        # Initial scan
        get_wifi_networks()
    
    def show_message(self, title, message):
        msg_window = tk.Toplevel(self.root)
        msg_window.title(title)
        msg_window.geometry("400x200")
        
        msg_label = tk.Label(msg_window, text=message, wraplength=350)
        msg_label.pack(pady=30)
        
        ok_btn = tk.Button(msg_window, text="OK", width=10, command=msg_window.destroy)
        ok_btn.pack(pady=20)

if __name__ == "__main__":
    root = tk.Tk()
    app = MagicFrameApp(root)
    root.config(cursor="none")  # Hides the mouse cursor
    root.mainloop()