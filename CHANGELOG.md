# Os2Display/CoreBundle CHANGELOG

## 1.2.0

* Changed MiddlewareCommunications to be event based, and moved improvements from CampaignBundle into CoreBundle.
* Added id to list over entities to delete with cleanup command.
* Added os2display:core:cleanup command to remove unused content from an installation.
* Removed broken slide references.
* Added is_null check for slides in channel.getPublishedSlides().
* Fixed missing sharing index serialization.

## 1.1.0

* Changed try/catch of FeedService to catch all exceptions.
* Fixed issue where shared channel was not serialized correctly, resulting in a blocked screen-timeline overview.
* Fixed behat features
* Added viewable_groups to apiData.
* Removed configuration call from base extension
* Added cron controll route. Reformatted code
* Fixed issue with slide template ikSlide.path variable.
* Fixed zencoder job naming.

## 1.0.11

* Merged PR: https://github.com/os2display/core-bundle/pull/5 allowing users to edit their own profile.

## 1.0.10

* Fixed zencoder job naming.
* Fixed issue with sharing_service event naming.
* Changed ScreenTemplateEntity->tools to json_array. Requires update of schema to use.
* Fixed screen save to database.

## 1.0.9

* Fixed issue with deleting group that contained content.

## 1.0.x < 1.0.9

* Move from 4.x structure to 5.x with core in core-bundle.
