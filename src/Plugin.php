<?php
/**
 * Phergie (http://phergie.org)
 *
 * @link https://github.com/MakeKlat00/phergie-irc-plugin-react-chanmodes for the canonical source repository
 * @copyright Copyright (c) 2008-2014 Phergie Development Team (http://phergie.org), 2015 MakeKlat00 (http://www.makeklat00.me.uk/)
 * @license http://phergie.org/license Simplified BSD License
 * @package MakeKlat00\Phergie\Irc\Plugin\ChanModes
 */

namespace MakeKlat00\Phergie\Irc\Plugin\ChanModes;

use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\EventQueueInterface;
use Phergie\Irc\ConnectionInterface;
use Phergie\Irc\Event\ServerEventInterface;
use Phergie\Irc\Event\UserEventInterface;

/**
 * Plugin for monitoring and providing access to channel mode information.
 *
 * @category Phergie
 * @package MakeKlat00\Phergie\Irc\Plugin\ChanModes
 */
class Plugin extends AbstractPlugin
{
    const CHANMODE_TYPE_LIST = 1;
    const CHANMODE_TYPE_PARAM_ALWAYS = 2;
    const CHANMODE_TYPE_PARAM_SETONLY = 3;
    const CHANMODE_TYPE_NOPARAM = 4;

    const ERR_CONFIG_MODETYPES_INVALID = 1;
    const ERR_CONFIG_PREFIXES_INVALID = 2;

    /**
     * Map of connection objects to chanmode and prefix maps.
     *
     * @var \SplObjectStorage
     */
    protected $connectionStore;

    /**
     * Mapping of connection objects to \ArrayObject objects, which themselves contain
     * a map of channel names to nicknames and prefix modes.
     *
     * @var \SplObjectStorage
     */
    protected $channelLists;

    /**
     * Default chanmode map.
     *
     * @var array
     */
    protected $defaultModeTypes = array(
        'b' => self::CHANMODE_TYPE_LIST, // ban
        'e' => self::CHANMODE_TYPE_LIST, // exempt
        'I' => self::CHANMODE_TYPE_LIST, // invex
        'k' => self::CHANMODE_TYPE_PARAM_ALWAYS, // channel key
        'l' => self::CHANMODE_TYPE_PARAM_SETONLY, // channel limit
        'i' => self::CHANMODE_TYPE_NOPARAM, // invite-only
        'm' => self::CHANMODE_TYPE_NOPARAM, // moderated
        'n' => self::CHANMODE_TYPE_NOPARAM, // no external privmsgs
        'p' => self::CHANMODE_TYPE_NOPARAM, // private
        's' => self::CHANMODE_TYPE_NOPARAM, // secret
        't' => self::CHANMODE_TYPE_NOPARAM, // topic lock
    );

    /**
     * Default prefix map.
     *
     * @var array
     */
    protected $defaultPrefixes = array(
        '@' => 'o', // op
        '%' => 'h', // halfop
        '+' => 'v', // voice
    );

    /**
     * Accepts configuration.
     *
     * Supported keys:
     *
     * defaultmodetypes - optional replacement for $defaultModeTypes
     * defaultprefixes - optional replacement for $defaultPrefixes
     *
     * @param array $config
     */
    public function __construct(array $config = array())
    {
        if (isset($config['defaultmodetypes'])) {
            if (!is_array($config['defaultmodetypes'])) {
                throw new \DomainException(
                    'Configuration option "defaultmodetypes" must be of type "array"',
                    self::ERR_CONFIG_MODETYPES_INVALID
                );
            }
            foreach ($config['defaultmodetypes'] as $key => $value) {
                if (!is_string($key) || strlen($key) != 1 || !is_int($value)) {
                    throw new \DomainException(
                        'The default mode type map provided is invalid',
                        self::ERR_CONFIG_MODETYPES_INVALID
                    );
                }
            }
            $this->defaultModeTypes = $config['defaultmodetypes'];
        }
        if (isset($config['defaultprefixes'])) {
            if (!is_array($config['defaultprefixes'])) {
                throw new \DomainException(
                    'Configuration option "defaultprefixes" must be of type "array"',
                    self::ERR_CONFIG_PREFIXES_INVALID
                );
            }
            foreach ($config['defaultprefixes'] as $key => $value) {
                if (!is_string($key) || strlen($key) != 1 || !is_string($value) || strlen($value) != 1) {
                    throw new \DomainException(
                        'The default prefix map provided is invalid',
                        self::ERR_CONFIG_PREFIXES_INVALID
                    );
                }
            }
            $this->defaultPrefixes = $config['defaultprefixes'];
        }

        foreach ($this->defaultPrefixes as $prefix => $mode) {
            $this->defaultModeTypes[$mode] = self::CHANMODE_TYPE_PARAM_ALWAYS;
        }

        $this->connectionStore = new \SplObjectStorage;
        $this->channelLists = new \SplObjectStorage;
    }

    /**
     * Event listeners.
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            'irc.sent.mode' => 'changePrefixModes',
            'irc.received.mode' => 'changePrefixModes',
            'irc.received.join' => 'addUserToChannel',
            'irc.received.part' => 'removeUserFromChannel',
            'irc.received.kick' => 'removeKickedUserFromChannel',
            'irc.received.quit' => 'removeUser',
            'irc.received.nick' => 'changeUserNick',
            'irc.received.rpl_namreply' => 'loadChannelList',
            'irc.received.rpl_isupport' => 'processCapabilities',
        );
    }

    /**
     * Gets the channel mode type of the given mode character,
     * as reported by the server.
     * Types are one of the class constants CHANMODE_TYPE_*
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param string $mode eg. 'm'
     * @param bool $debug (optional)
     *
     * @return int|bool Mode type, or false on failure
     */
    public function getChannelModeType(ConnectionInterface $connection, $mode, $debug = true)
    {
        $logger = $this->getLogger();

        if (!is_string($mode) || strlen($mode) != 1) {
            $logger->warning('getChannelModeType: invalid argument', array('mode' => $mode));
            return false;
        }

        $store = $this->connectionStore;
        if ($store->contains($connection) && isset($store[$connection]['modes'])) {
            $modeMap = $store[$connection]['modes'];
        } else {
            if ($debug) {
                $logger->debug('getChannelModeType: no mode map found, using default');
            }
            $modeMap = $this->defaultModeTypes;
        }

        return isset($modeMap[$mode]) ? $modeMap[$mode] : false;
    }

    /**
     * Gets the prefix character corresponding to the specified channel mode,
     * as reported by the server.
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param string $mode eg. 'o'
     * @param bool $debug (optional)
     *
     * @return string|bool Prefix character, or false on failure
     */
    public function getPrefixFromChannelMode(ConnectionInterface $connection, $mode, $debug = true)
    {
        $logger = $this->getLogger();

        if (!is_string($mode) || strlen($mode) != 1) {
            $logger->warning('getPrefixFromChannelMode: invalid argument', array('mode' => $mode));
            return false;
        }

        $store = $this->connectionStore;
        if ($store->contains($connection) && isset($store[$connection]['prefixes'])) {
            $prefixMap = $store[$connection]['prefixes'];
        } else {
            if ($debug) {
                $logger->debug('getPrefixFromChannelMode: no prefix map found, using default');
            }
            $prefixMap = $this->defaultPrefixes;
        }

        return array_search($mode, $prefixMap, true);
    }

    /**
     * Gets the mode character corresponding to the specified prefix,
     * as reported by the server.
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param string $prefix eg. '@'
     * @param bool $debug (optional)
     *
     * @return string|bool Mode character, or false on failure
     */
    public function getChannelModeFromPrefix(ConnectionInterface $connection, $prefix, $debug = true)
    {
        $logger = $this->getLogger();

        if (!is_string($prefix) || strlen($prefix) != 1) {
            $logger->warning('getChannelModeFromPrefix: invalid argument', array('prefix' => $prefix));
            return false;
        }

        $store = $this->connectionStore;
        if ($store->contains($connection) && isset($store[$connection]['prefixes'])) {
            $prefixMap = $store[$connection]['prefixes'];
        } else {
            if ($debug) {
                $logger->debug('getChannelModeFromPrefix: no prefix map found, using default');
            }
            $prefixMap = $this->defaultPrefixes;
        }

        return isset($prefixMap[$prefix]) ? $prefixMap[$prefix] : false;
    }

    /**
     * Returns the map of prefixes to modes.
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param bool $debug (optional)
     * @return array
     */
    public function getPrefixMap(ConnectionInterface $connection, $debug = true)
    {
        $store = $this->connectionStore;
        if ($store->contains($connection) && isset($store[$connection]['prefixes'])) {
            return $store[$connection]['prefixes'];
        }

        if ($debug) {
            $this->getLogger()->debug('getPrefixMap: no prefix map found, returning default');
        }
        return $this->defaultPrefixes;
    }

    /**
     * Takes a list of mode changes and params, and separates them out into individual
     * mode/param pairs, according to the mode type reported by the server.
     *
     * Returns an array of arrays consisting of the following keys:
     * 'operation' - [+/-]
     * 'mode' - the individual mode character
     * 'prefix' - the prefix corresponding to that mode, if applicable
     * 'param' - the parameter corresponding to that mode, if applicable
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param string $modes eg. '+mvv-k'
     * @param string $params eg. 'VoicedUser1 VoicedUser1 OldChannelKey' (optional)
     *
     * @return array As above, or empty array on failure
     */
    public function parseChannelModeChange(ConnectionInterface $connection, $modes, $params = null)
    {
        $logger = $this->getLogger();
        $logMessageContext = array('modes' => $modes, 'params' => $params);

        if (!is_string($modes) || ($params !== null && !is_string($params))) {
            $logger->warning('parseChannelModeChange: invalid arguments', $logMessageContext);
            return [];
        }

        // Detect no-op
        if (!strlen(str_replace(['+', '-'], '', $modes))) {
            return [];
        }

        if (!$this->connectionStore->contains($connection) || !isset($this->connectionStore[$connection]['modes'])) {
            $logger->debug('parseChannelModeChange: no chanmode map found, using default');
        }
        if (!$this->connectionStore->contains($connection) || !isset($this->connectionStore[$connection]['prefixes'])) {
            $logger->debug('parseChannelModeChange: no prefix map found, using default');
        }

        $parsed = [];
        $modes = str_split($modes);
        $params = ($params !== null) ? array_filter(explode(' ', $params)) : [];
        $operation = '';

        // Special case: list request
        if (empty($params) && !in_array('-', $modes, true)) {
            $chars = array_diff(array_unique($modes), ['+']);
            $filtered = array_filter($chars, function($char) use ($connection) {
                return ($this->getChannelModeType($connection, $char, false) == self::CHANMODE_TYPE_LIST);
            });
            if ($chars == $filtered) {
                $logger->debug('parseChannelModeChange: input is a list request');
                foreach ($chars as $char) {
                    $parsed[] = array('mode' => $char);
                }
                return $parsed;
            }
        }

        foreach ($modes as $char) {
            switch ($char) {
                case '+':
                case '-':
                    $operation = $char;
                    break;

                default:
                    if (!$operation) {
                        $logger->warning('parseChannelModeChange: no operation found', $logMessageContext);
                        return [];
                    }

                    $modeType = $this->getChannelModeType($connection, $char, false);
                    if ($modeType === false) {
                        $logger->warning("parseChannelModeChange: chanmode $char not recognised", $logMessageContext);
                        return [];
                    }

                    switch ($modeType) {
                        case self::CHANMODE_TYPE_LIST:
                        case self::CHANMODE_TYPE_PARAM_ALWAYS:
                            if (empty($params)) {
                                $logger->warning('parseChannelModeChange: not enough params', $logMessageContext);
                                return [];
                            }
                            if (($prefix = $this->getPrefixFromChannelMode($connection, $char, false)) !== false) {
                                $parsed[] = array('operation' => $operation, 'mode' => $char, 'prefix' => $prefix, 'param' => array_shift($params));
                            } else {
                                $parsed[] = array('operation' => $operation, 'mode' => $char, 'param' => array_shift($params));
                            }
                            break;

                        case self::CHANMODE_TYPE_PARAM_SETONLY:
                            if ($operation == '-') {
                                $parsed[] = array('operation' => $operation, 'mode' => $char);
                            } else {
                                if (empty($params)) {
                                    $logger->warning('parseChannelModeChange: not enough params', $logMessageContext);
                                    return [];
                                }
                                $parsed[] = array('operation' => $operation, 'mode' => $char, 'param' => array_shift($params));
                            }
                            break;

                        case self::CHANMODE_TYPE_NOPARAM:
                            $parsed[] = array('operation' => $operation, 'mode' => $char);
                            break;

                        default:
                            $logger->warning('parseChannelModeChange: corrupted mode store');
                            return [];
                            break;
                    }

                    break;
            }
        }

        if (!empty($params)) {
            $logger->warning('parseChannelModeChange: too many params', $logMessageContext);
            return [];
        }

        return $parsed;
    }

    /**
     * Monitors prefix mode changes.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function changePrefixModes(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();
        $params = $event->getParams();

        // Disregard mode changes that are not applicable
        if (!isset($params['channel'])) {
            $logger->debug('Not a channel mode change, skipping');
            return;
        }
        if (!isset($params['params'])) {
            $logger->debug('No trailing parameters, skipping');
            return;
        }

        $connection = $event->getConnection();
        if (!$this->channelLists->contains($connection)) {
            $this->channelLists->attach($connection, new \ArrayObject);
        }

        $modesArray = $this->channelLists[$connection];
        $channel = $params['channel'];
        $changes = $this->parseChannelModeChange($connection, $params['mode'], $params['params']);

        // Something went wrong...
        if (empty($changes)) {
            $logger->debug('Could not parse mode change, refreshing prefixes');
            if (isset($modesArray[$channel])) {
                unset($modesArray[$channel]);
            }
            $queue->ircNames($channel);
            return;
        }

        foreach ($changes as $change) {
            if (empty($change['prefix'])) {
                continue;
            }

            $operation = $change['operation'];
            $mode = $change['mode'];
            $prefix = $change['prefix'];
            $nick = $change['param'];

            switch ($operation) {
                case '+':
                    $logger->debug('Adding user mode', array(
                        'channel' => $channel,
                        'nick' => $nick,
                        'mode' => $mode,
                        'prefix' => $prefix,
                    ));

                    $modesArray[$channel][$nick][$mode] = true;
                    break;

                case '-':
                    $logger->debug('Removing user mode', array(
                        'channel' => $channel,
                        'nick' => $nick,
                        'mode' => $mode,
                        'prefix' => $prefix,
                    ));

                    unset($modesArray[$channel][$nick][$mode]);
                    break;
            }
        }
    }

    /**
     * Adds a user index to the channel.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function addUserToChannel(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();
        $connection = $event->getConnection();
        if (!$this->channelLists->contains($connection)) {
            $this->channelLists->attach($connection, new \ArrayObject);
        }

        $modesArray = $this->channelLists[$connection];
        $params = $event->getParams();
        $channels = explode(',', $params['channels']);
        $nick = $event->getNick();
        foreach ($channels as $channel) {
            if (!isset($modesArray[$channel][$nick])) {
                $logger->debug('Adding user to channel', array(
                    'channel' => $channel,
                    'nick' => $nick,
                ));
                $modesArray[$channel][$nick] = [];
            }
        }
    }

    /**
     * Removes user mode data that's no longer needed.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function removeUserFromChannel(UserEventInterface $event, EventQueueInterface $queue)
    {
        $connection = $event->getConnection();
        if (!$this->channelLists->contains($connection)) {
            return;
        }

        $logger = $this->getLogger();
        $params = $event->getParams();
        $nick = $event->getNick();
        $channels = explode(',', $params['channels']);

        if ($nick == $connection->getNickname()) {
            $modesArray = $this->channelLists[$connection];
            foreach ($channels as $channel) {
                $logger->debug('Deleting all modes for channel', array('channel' => $channel));
                unset($modesArray[$channel]);
            }
            return;
        }

        $this->removeUserData($connection, $channels, $nick);
    }

    /**
     * Removes user mode data that's no longer needed.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function removeKickedUserFromChannel(UserEventInterface $event, EventQueueInterface $queue)
    {
        $connection = $event->getConnection();
        if (!$this->channelLists->contains($connection)) {
            return;
        }

        $logger = $this->getLogger();
        $params = $event->getParams();
        $nick = $params['user'];
        $channel = $params['channel'];

        if ($nick == $connection->getNickname()) {
            $logger->debug('Deleting all modes for channel', array('channel' => $channel));
            unset($this->channelLists[$connection][$channel]);
            return;
        }

        $this->removeUserData($connection, array($channel), $nick);
    }

    /**
     * Removes user mode data that's no longer needed.
     *
     * @param \Phergie\Irc\Event\EventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function removeUser(UserEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();
        $connection = $event->getConnection();

        if ($event->getNick() == $connection->getNickname()) {
            $logger->debug('Deleting all modes for connection');
            $this->channelLists->detach($connection);
            return;
        }

        if (!$this->channelLists->contains($connection)) {
            return;
        }

        $this->removeUserData(
            $connection,
            array_keys($this->channelLists[$connection]->getArrayCopy()),
            $event->getNick()
        );
    }

    /**
     * Removes mode data for a user and list of channels.
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param array $channels
     * @param string $nick
     */
    protected function removeUserData(ConnectionInterface $connection, array $channels, $nick)
    {
        if (!$this->channelLists->contains($connection)) {
            return;
        }

        $logger = $this->getLogger();
        $modesArray = $this->channelLists[$connection];
        foreach ($channels as $channel) {
            if (!isset($modesArray[$channel][$nick])) {
                continue;
            }
            $logger->debug('Removing user mode data', array(
                'channel' => $channel,
                'nick' => $nick,
            ));
            unset($modesArray[$channel][$nick]);
        }
    }

    /**
     * Accounts for user nick changes in stored data.
     *
     * @param \Phergie\Irc\Event\EventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function changeUserNick(UserEventInterface $event, EventQueueInterface $queue)
    {
        $connection = $event->getConnection();
        if (!$this->channelLists->contains($connection)) {
            return;
        }

        $logger = $this->getLogger();
        $modesArray = $this->channelLists[$connection];
        $old = $event->getNick();
        $params = $event->getParams();
        $new = $params['nickname'];
        foreach (array_keys($modesArray->getArrayCopy()) as $channel) {
            if (!isset($modesArray[$channel][$old])) {
                continue;
            }
            $logger->debug('Moving user mode data', array(
                'channel' => $channel,
                'oldNick' => $old,
                'newNick' => $new,
            ));
            $modesArray[$channel][$new] = $modesArray[$channel][$old];
            unset($modesArray[$channel][$old]);
        }
    }

    /**
     * Loads user mode data when an RPL_NAMREPLY line, either
     * on initial channel join or in response to a NAMES request.
     *
     * @param \Phergie\Irc\Event\ServerEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function loadChannelList(ServerEventInterface $event, EventQueueInterface $queue)
    {
        $logger = $this->getLogger();
        $connection = $event->getConnection();
        if (!$this->channelLists->contains($connection)) {
            $this->channelLists->attach($connection, new \ArrayObject);
        }

        $modesArray = $this->channelLists[$connection];
        $params = array_slice($event->getParams(), 1);
        $channel = array_shift($params);
        $validPrefixes = preg_quote(implode('', array_keys($this->getPrefixMap($connection, false))));
        $pattern = "/^([$validPrefixes]*)([^$validPrefixes]+)$/";

        // The names should be in a single trailing param, but just in case...
        $names = (count($params) == 1) ? explode(' ', $params[0]) : $params;
        foreach ($names as $fullNick) {
            if (!preg_match($pattern, $fullNick, $match)) {
                continue;
            }
            $nickPrefixes = strlen($match[1]) ? str_split($match[1]) : [];
            $nick = $match[2];

            $logger->debug('Adding user to channel', array(
                'channel' => $channel,
                'nick' => $nick,
            ));
            $modesArray[$channel][$nick] = [];

            foreach ($nickPrefixes as $prefix) {
                $mode = $this->getChannelModeFromPrefix($connection, $prefix, false);
                $logger->debug('Recording user mode', array(
                    'channel' => $channel,
                    'nick' => $nick,
                    'mode' => $mode,
                ));
                $modesArray[$channel][$nick][$mode] = true;
            }
        }
    }

    /**
     * Returns whether a user has a particular prefix mode in a particular channel.
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param string $channel
     * @param string $nick
     * @param string $mode
     * @return bool
     */
    public function userHasPrefixMode(ConnectionInterface $connection, $channel, $nick, $mode)
    {
        return ($this->channelLists->contains($connection) && isset($this->channelLists[$connection][$channel][$nick][$mode]));
    }

    /**
     * Returns a list of prefix modes for a user in a particular channel.
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param string $channel
     * @param string $nick
     * @return array Enumerated array of mode letters or an empty array if the
     *         user has no modes in the specified channel
     */
    public function getUserPrefixModes(ConnectionInterface $connection, $channel, $nick)
    {
        if (!$this->channelLists->contains($connection)) {
            return [];
        }

        $modesArray = $this->channelLists[$connection];
        return isset($modesArray[$channel][$nick]) ? array_keys($modesArray[$channel][$nick]) : [];
    }

    /**
     * Returns whether a user is in a particular channel.
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param string $channel
     * @param string $nick
     * @return bool
     */
    public function isUserInChannel(ConnectionInterface $connection, $channel, $nick)
    {
        return ($this->channelLists->contains($connection) && isset($this->channelLists[$connection][$channel][$nick]));
    }

    /**
     * Returns a list of users in a particular channel.
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param string $channel
     * @return array Enumerated array of nicknames, or an empty array if the
     *         channel does not exist in the store
     */
    public function getChannelUsers(ConnectionInterface $connection, $channel)
    {
        if (!$this->channelLists->contains($connection)) {
            return [];
        }

        $modesArray = $this->channelLists[$connection];
        return isset($modesArray[$channel]) ? array_keys($modesArray[$channel]) : [];
    }

    /**
     * Returns a list of active channels for a particular user.
     *
     * @param \Phergie\Irc\ConnectionInterface $connection
     * @param string $nick
     * @return array Enumerated array of channel names
     */
    public function getUserChannels(ConnectionInterface $connection, $nick)
    {
        if (!$this->channelLists->contains($connection)) {
            return [];
        }

        $modesArray = $this->channelLists[$connection];
        return array_values(array_filter(
            array_keys($modesArray->getArrayCopy()),
            function($channel) use ($modesArray, $nick) {
                return isset($modesArray[$channel][$nick]);
            }
        ));
    }

    /**
     * Generates the chanmode/prefix maps and enables NAMESX if supported.
     *
     * @param \Phergie\Irc\Event\ServerEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processCapabilities(ServerEventInterface $event, EventQueueInterface $queue)
    {
        $connection = $event->getConnection();
        $logger = $this->getLogger();
        $store = $this->connectionStore;
        if (!$store->contains($connection)) {
            $store->attach($connection, new \ArrayObject);
        }

        foreach ($event->getParams()['iterable'] as $param) {
            if ($param == 'NAMESX') {
                $queue->ircProtoctl('NAMESX');
            }
            elseif (preg_match('/^CHANMODES=([^,]*),((?1)),((?1)),((?1))$/', $param, $matches)) {
                $logger->debug('Parsing chanmode types from RPL_ISUPPORT');
                $chanModeTypes = [];

                foreach (array(
                    1 => self::CHANMODE_TYPE_LIST,
                    2 => self::CHANMODE_TYPE_PARAM_ALWAYS,
                    3 => self::CHANMODE_TYPE_PARAM_SETONLY,
                    4 => self::CHANMODE_TYPE_NOPARAM,
                ) as $index => $type) {
                    if (!empty($matches[$index])) {
                        $chanModeTypes += array_fill_keys(str_split($matches[$index]), $type);
                    }
                }

                if (!empty($store[$connection]['modes'])) {
                    $chanModeTypes += $store[$connection]['modes'];
                }
                $store[$connection]['modes'] = $chanModeTypes;
            }
            elseif (preg_match('/^PREFIX=\((\S+)\)(\S+)$/', $param, $matches)
            && strlen($matches[1]) == strlen($matches[2])) {
                $logger->debug('Parsing prefixes from RPL_ISUPPORT');

                $prefixModes = str_split($matches[1]);
                $store[$connection]['prefixes'] = array_combine(str_split($matches[2]), $prefixModes);

                $chanModeTypes = array_fill_keys($prefixModes, self::CHANMODE_TYPE_PARAM_ALWAYS);
                if (!empty($store[$connection]['modes'])) {
                    $chanModeTypes += $store[$connection]['modes'];
                }
                $store[$connection]['modes'] = $chanModeTypes;
            }
        }
    }
}
