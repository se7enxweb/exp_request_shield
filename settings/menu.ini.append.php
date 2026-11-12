<?php /* #?ini charset="utf-8"?

# Setup > Request shield: the dashboard, log and rules of the request filter, shown only to a user with the policy
# requestshield/read (the Administrator role holds every policy). In admin4's grouped Setup menu it stands in the
# group "System".
[Leftmenu_setup]
Links[request_shield]=requestshield/dashboard
LinkNames[request_shield]=Request shield
PolicyList_request_shield[]=requestshield/read
GroupLinks_system[]=request_shield
LinkIcons[request_shield]=shield-lock
LinkDescriptions[request_shield]=See what the request filter in front of the site decides, its log and its rules.

*/ ?>
