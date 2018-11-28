# Os2Display/CoreBundle CHANGELOG

## 1.2.0

* Added os2display:core:cleanup command to remove unused content from an installation.

## 1.1.5

* Removed broken slide references.

## 1.1.4

* Added is_null check for slides in channel.getPublishedSlides().

## 1.1.3

* Fixed missing sharing index serialization.

## 1.1.2

* Changed try/catch of FeedService to catch all exceptions.

## 1.1.1

* Fixed issue where shared channel was not serialized correctly, resulting in a blocked screen-timeline overview.

## 1.1.0

* Fixed behat features
* Added viewable_groups to apiData.

## 1.0.14

* Removed configuration call from base extension
* Added cron controll route. Reformatted code

## 1.0.13

* Fixed issue with slide template ikSlide.path variable.

## 1.0.12

* Fixed zencoder job naming.

## 1.0.11

* Fixed issue with sharing_service event naming.

## 1.0.10

* Changed ScreenTemplateEntity->tools to json_array. Requires update of schema to use.
* Fixed screen save to database.

## 1.0.9

* Fixed issue with deleting group that contained content.

## 1.0.x < 1.0.9

* Move from 4.x structure to 5.x with core in core-bundle.
