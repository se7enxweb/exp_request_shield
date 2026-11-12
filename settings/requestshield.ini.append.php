<?php /* #?ini charset="utf-8"?

# exp_request_shield: settings of the admin pages (Setup > Request shield). The shield itself is configured in its
# rule files, never here: it runs before the kernel reads any INI file. See doc/configuration.md.

[AdminSettings]
# How many hours of the log the dashboard sums up and draws (1 to 168).
DashboardHours=24
# How many of the newest log lines the dashboard lists.
DashboardRecent=15
# The choices of the log page's period, in hours.
LogHours[]
LogHours[]=1
LogHours[]=6
LogHours[]=24
LogHours[]=72
LogHours[]=168
# How many lines the log page shows per page.
LogPageSize=50
# Show the text of the rule files on the rules page (enabled|disabled).
ShowRuleText=enabled

*/ ?>
