# nagios-service-search
Simple status.dat search for strings in services.

I take only very minimal credit for this; it leverages Jason Antman's statusXML Nagios Exchange project (https://exchange.nagios.org/directory/Addons/APIs/XML/Status-XML-Generator/details) and then does some hacked-up multidimensional array searching on it.

Usage: pass the required URL parameters (detailed in the code near the bottom):

s: search string
srt: sort column (from status.dat); c=state, d=service description, h=host name
srto: sort order, a or d

Add a call to sidebar.html or whereever you think it seems appropriate.

