# ISPConfig-DynDNS
Create your own dynamic DNS on ISPConfig

# Installation
- Create a Remote User: ISP Config -> System -> Remote Users -> Add new user
- Enable Remote Access and two Functions: DNS a functions and DNS zone functions
- Add dyndns.php to your website that it's hosted on ISPConfig
- Add ddns.sh to you box and add it to crontab
- Update dyndns.php and ddns.sh with your creds, client_id, zone_id, etc.
- enjoy
