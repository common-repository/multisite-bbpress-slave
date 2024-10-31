=== Multisite bbPress Slave ===
Contributors: mechter
Donate link: http://www.markusechterhoff.com/donation/
Tags: bbpress, multisite
Requires at least: 3.6
Tested up to: 4.1.1
Stable tag: 1.0
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Make sub sites use the main site's bbPress forums

== Description ==

If you have a multisite setup, you normally cannot have the same bbPress forums across all sites of the network. Now you can.

Install this plugin and activate it on the main site and for each subsite that should use the main site's forums, or network-activate to enable on all sub sites.

If you want both main site and sub site forums displayed on the sub site, set a different root slug and topics slug in the forum settings. After that, both slugs will be known on the sub site and display their respective forums.

**BUT!** There is a reason why you cannot normally do this and that is because the code base is not really supportive of it. I have monkey patched my way to the goal and I'm doing some evil black magic like hooking 'query' and running regular expressions on the query. All of this is only ever done to your forum pages, so be cool, give it a try. If it works for you: great. If it doesn't: deactivate, relax, look for a different solution (or patch mine).

**Profile Note:** Profiles are not merged! Favorites, topics started etc show main/sub site only. If you want your users to see all their subscriptions in one place, you'll have to write some custom code and likely patch my code too.

**BuddyPress Note:** It does work with network enabled BuddyPress, but they don't play together nicely because of the BuddyPress/bbPress integration stuff. I have monkey patched this to work. If you do not have BuddyPress installed in /members/ you'll have to monkey patch my monkey patch, please see the code. And as for the profiles: Same thing as with bbPress profiles.

== Changelog ==

= 1.0 =

* initial release
