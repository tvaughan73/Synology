# Synology
PHP Library for Synology DSM 6

All current PHP libraries on github for Synology DSM are old and will not work with DSM 6. Documentation for DSM 6 is pretty much non-existent.  This library will work with DSM 6 and is intended as a starting point for others.  All of the methods in this library were derived from observing the request and response from the DiskStation UI using Chrome developer tools.  

Synology.php was written for Codeigniter framework and also uses composer libraries for Redis and Google 2FA.

`composer require predis/predis`

`composer require pragmarx/google2fa`

## Redis
When connecting to DSM you will first need to submit an authorization request and get back a session ID token (sid).  The purpose of using Redis is to cache this token so it can be reused.  DSM does not expect to repeatedly receive authorization requests and will sometimes fail if it does.  Redis is not require here but you will need some way to cache the sid.  Either use some other memory cache or save to a file.

## Google 2FA
If 2FA is enabled on DSM you will require the 2FA code be submitted with the authorization request.  The 2FA library used here has a method to retrieve this value using the key given by DSM when a user is first logged in through the browser.

## Constructing New Methods
If you want to figure out what needs to be included in a method you would:
1. Open DSM in Chrome.  
2. Open developer tools Ctrl + Shift + i
3. Click Network tab.
4. In DSM do whatever function you would like to know correct parameters. Create a user for example.
5. Observe the parameters being sent to DSM in the headers tab and DSM response in the response tab.


