ofxparser
=========

An OFX file parser written in PHP

Oct 6 2012:
This is currently in a very early stage of development. Most of the meat is in the OFXFile class. The ofxparser file is really a harness
for the class. As of right now, the OFXFile class will take an OFX file and parse it without a DTD and output the transaction list to
stdout. At some point, I'd like to validate against a DTD, but at the moment all I really want to do is parse an OFX file so that I can 
make my own Personal Finance app (we'll see how far down that road I can get). 

All I've tested this on so far is an OFX 1.0 file from my bank.
