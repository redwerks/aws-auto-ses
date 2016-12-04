=== AWS Automatic SES ===
Contributors: redwerks, danielfriesen
Tags: amazon, AWS, SES, email, mail, wp_mail
Requires at least: 4.3
Tested up to: 4.6
Stable tag: 0.0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically configure WordPress to use SES when on an EC2 instance with a SES capable role.

== Description ==

AWS Automatic SES configures WordPress run on an EC2 instance to use SES for email delivery.

This plugin is **not** for generic use of SES. If you want to use SES outside of EC2 or have more control over the settings, then use another [SES](https://wordpress.org/plugins/tags/ses) email plugin.

* Region is automatically picked based on the region the EC2 instance is run in.
* The EC2 instance's Role is used and expected to have the necessary permissions for sending raw emails.
* Only sender addresses verified for use with SES are used.

AWS permissions:

* `ses:SendRawEmail` (**required**) – Required for basic email sending functionality.
* `ses:GetIdentityVerificationAttributes` – If granted, will be used to warn if the default sender is not verified in SES. Required if you want AWS Automatic SES to permit the use of any from address that is verified in SES.
* `ses:ListIdentities` (*optional*) – Optionally used to display a short list of identities verified in SES on the settings page.

== Installation ==

1. Install aws-auto-ses from the WordPress.org plugin repository.
2. Activate it on your plugins page.
3. Use the **SES Options** settings page to ensure your role has the correct permissions and the sender(s) are verified.
4. On the **SES Options** page enable delivery via SES.

== Changelog ==

= 0.0.1 =
* Initial version
