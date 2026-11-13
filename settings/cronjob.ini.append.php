<?php /* #?ini charset="utf-8"?

[CronjobSettings]
ExtensionDirectories[]=exp_request_shield
# Once a day: the public blocklists the rules name (feed ...) and the crawlers' published address lists.
CrontabSchedule_requestshield=23 4 * * *

# The part requestshield: fetches the blocklists (request-shield feeds update) and the crawlers' address lists
# (request-shield crawlers update) for the site's main rule file, so "feed" rules and crawler verification stay
# current. Run as the user the site runs as (the crontab installer writes it into that user's crontab).
[CronjobPart-requestshield]
Scripts[]
Scripts[]=requestshield.php

[CrontabSettings]
Parts[]=requestshield

*/ ?>
