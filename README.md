## Magic frame 
This project builds a webserver that can hold images and share them between users. 

The folder website contains the code to setup on your webserver. 
I used appache2, php and sql3.

The folder Raspberrypi contains the code that runs on your raspi (the magicframe).

# Setup
Clone the repo onto your pi. 

gointo the setup folder and adapt the user name from kingu to the name of your pi.
then give the correct permission to the setupscript and execute it.

chmod +x Raspberrypi/setupscript.sh
sudo ./Raspberrypi/setupscript.sh

In case the location of your repo differs from mine adjust the path inside the setup file or afterwards in  the auto start file. 
After the restart the script should run automatically. 

# Update 
ATTENTION the script automatically pulls one every reboot. if you do not want that delete that line from your script.

# Display
For easier building I rotated the output. if you do not want that remove the rotation section of the setup script.
