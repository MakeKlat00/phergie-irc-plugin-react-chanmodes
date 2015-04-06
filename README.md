# phergie/phergie-irc-plugin-react-usermode

[Phergie](http://github.com/phergie/phergie-irc-bot-react/) plugin for monitoring and providing access to channel mode information.

It provides the following functionality:
* Parsing and storing the channel mode and prefix maps received from IRC servers
* Parsing channel mode changes
* Storing and maintaining lists of users and their prefix modes in each channel

[![Build Status](https://secure.travis-ci.org/phergie/phergie-irc-plugin-react-usermode.png?branch=master)](http://travis-ci.org/phergie/phergie-irc-plugin-react-usermode)

## Install

The recommended method of installation is [through composer](http://getcomposer.org).

```JSON
{
    "require": {
        "phergie/phergie-irc-plugin-react-usermode": "dev-master"
    }
}
```

See Phergie documentation for more information on
[installing and enabling plugins](https://github.com/phergie/phergie-irc-bot-react/wiki/Usage#plugins).

## Configuration

```php
<?php

use \Phergie\Irc\Plugin\React\UserMode\Plugin as UserModePlugin;

$userModePlugin = new UserModePlugin(array(
    // All optional
    'defaultmodetypes' => array(
        'b' => UserModePlugin::CHANMODE_TYPE_LIST,
        't' => UserModePlugin::CHANMODE_TYPE_NOPARAM,
        // ...
    ),
    'defaultprefixes' => array(
        '@' => 'o',
        '+' => 'v',
        // ...
    ),
));

return array(

    'connections' => array(
        // ...
    ),

    'plugins' => array(

        $userModePlugin,

        new \Plugin\That\Uses\UserMode\Plugin(array(
            'usermode' => $userModePlugin,
        )),

        // ...

    ),

);
```

This plugin has two optional configuration settings:
* `defaultmodetypes` overrides the default mapping of channel modes to mode types, which
  will be used if no mode map is received from the server.
  It should be an array of `MODE => TYPE` pairs, where `MODE` is a single character and
  `TYPE` is one of the class constants `CHANMODE_TYPE_*`. (You should not override
  prefix-style channel modes here &ndash; use `defaultprefixes` instead.)
* `defaultprefixes` overrides the default mapping of prefixes to channel modes, which
  will be used if no prefix map is received from the server.
  It should be an array of `PREFIX => MODE` pairs, where `PREFIX` is a single character
  and `MODE` is a single character.

## Public methods

#### getChannelUsers
``` php
array Plugin::getChannelUsers(\Phergie\Irc\ConnectionInterface $connection, string $channel)
```
Returns a list of users in a particular channel.

#### getChannelModeType
``` php
mixed Plugin::getChannelModeType(\Phergie\Irc\ConnectionInterface $connection, string $mode)
```
Get the mode type of a particular channel mode. The return value will be one of the
[class constants](https://github.com/Renegade334/phergie-irc-plugin-react-chanmodeparser/blob/cc4671561bb7e46267b70750a78cb286abd2f2db/src/Plugin.php#L26-29)
`CHANMODE_TYPE_*`, or `false` if no such mode exists.

#### getChannelModeFromPrefix
``` php
mixed Plugin::getChannelModeFromPrefix(\Phergie\Irc\ConnectionInterface $connection, string $prefix)
```
Get the channel mode corresponding to the given prefix, or `false` if the prefix does not exist.

#### getPrefixFromChannelMode
``` php
mixed Plugin::getPrefixFromChannelMode(\Phergie\Irc\ConnectionInterface $connection, string $mode)
```
Get the prefix corresponding to the given channel mode, or `false` if the mode does not exist or the mode
is not a prefix-type mode.

#### getPrefixMap
``` php
array Plugin::getPrefixMap(\Phergie\Irc\ConnectionInterface $connection)
```
Get the prefix map for the given connection. The return value will be an array of
`PREFIX => MODE` pairs.

#### getUserChannels
``` php
array Plugin::getUserChannels(\Phergie\Irc\ConnectionInterface $connection, string $nick)
```
Returns a list of active channels for a particular user.

#### getUserPrefixModes
``` php
array Plugin::getUserPrefixModes(\Phergie\Irc\ConnectionInterface $connection, string $channel, string $nick)
```
Returns a list of prefix-type modes held by a given user in a particular channel.

#### isUserInChannel
``` php
bool Plugin::isUserInChannel(\Phergie\Irc\ConnectionInterface $connection, string $channel, string $nick)
```
Returns whether a user is in a particular channel.

#### parseChannelModeChange
``` php
array Plugin::parseChannelModeChange(\Phergie\Irc\ConnectionInterface $connection, string $modes [, string $params ])
```
Takes a given mode change string with optional trailing parameters, and separates it into individual modes
with corresponding parameters.

The return value will be an array of arrays corresponding to individual mode changes, containing the following keys:
* `'operation' =>` `'+'` or `'-'`
* `'mode' =>` the individual mode character
* `'prefix' =>` the prefix corresponding to that mode, if applicable
* `'param' =>` the trailing parameter corresponding to that mode, if applicable

There is one special case: a list mode request, where `$modes` contains only list-type modes and `$params` is empty.
In this case, the return value will be an array of arrays which contain a single key:
* `'mode' =>` the individual list mode character

Returns the empty array on failure.

#### userHasPrefixMode
``` php
bool Plugin::userHasPrefixMode(\Phergie\Irc\ConnectionInterface $connection, string $channel, string $nick, string $mode)
```
Returns whether a user has a particular prefix-type mode in the specified channel.

## Usage

```php
use Phergie\Irc\Bot\React\PluginInterface;
use Phergie\Irc\Bot\React\EventQueueInterface;
use Phergie\Irc\Plugin\React\Command\CommandEvent;

class FooPlugin implements PluginInterface
{
    /**
     * @var \Phergie\Irc\Plugin\React\UserMode\Plugin
     */
    protected $userModePlugin;

    public function __construct(array $config)
    {
        // Validate $config['usermode']

        $this->userModePlugin = $config['usermode'];
    }

    public function getSubscribedEvents()
    {
        return array(
            'command.foo' => 'handleFooCommand',
        );
    }

    public function handleFooCommand(CommandEvent $event, EventQueueInterface $queue)
    {
        $connection = $event->getConnection();
        $nick = $event->getNick();
        $params = $event->getParams();
        $source = $event->getCommand() === 'PRIVMSG'
            ? $params['receivers']
            : $params['nickname'];

        // Ignore events sent directly to the bot rather than to a channel
        if ($connection->getNickname() === $source) {
            return;
        }

        // Don't process the command if the user is not a channel operator
        if (!$this->userModePlugin->userHasPrefixMode($connection, $source, $nick, 'o')) {
            return;
        }

        // The user is a channel operator, continue processing the command
        // ...
    }
}
```

## Differences from UserMode

If you are migrating your plugin from the former UserMode plugin, there are some differences to be aware of.

1. The following public functions have new names:
   * `userHasMode` &rarr; `userHasPrefixMode`
   * `getUserModes` &rarr; `getUserPrefixModes`
2. The plugin now parses the server's own reported prefix map, meaning that you should never have to specify
   your own. The option has been made available to provide the default prefix map, but this will only be used
   if no prefix map is received from the server, which usually means that something's gone wrong. (Note that the
   name for this config option has changed.)
3. Channel lists are no longer stored in an array keyed by "connection mask", but are now stored in an
   object store containing connection instances mapped to `ArrayObject` objects. This will only be relevant
   if you are extending the plugin class.

## Tests

To run the unit test suite:

```
curl -s https://getcomposer.org/installer | php
php composer.phar install
./vendor/bin/phpunit
```

## License

Released under the BSD License. See `LICENSE`.
