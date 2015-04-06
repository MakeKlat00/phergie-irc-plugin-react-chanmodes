<?php
/**
 * Phergie (http://phergie.org)
 *
 * @link https://github.com/phergie/phergie-irc-plugin-react-usermode for the canonical source repository
 * @copyright Copyright (c) 2008-2014 Phergie Development Team (http://phergie.org)
 * @license http://phergie.org/license Simplified BSD License
 * @package Phergie\Irc\Plugin\React\UserMode
 */

namespace Phergie\Irc\Tests\Plugin\React\UserMode;

use Phake;
use Phergie\Irc\Bot\React\EventQueueInterface;
use Phergie\Irc\Event\ServerEventInterface;
use Phergie\Irc\Event\UserEventInterface;
use Phergie\Irc\Plugin\React\UserMode\Plugin;

/**
 * Tests for the Plugin class.
 *
 * @category Phergie
 * @package Phergie\Irc\Plugin\React\UserMode
 */
class PluginTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Mock logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Mock event queue
     *
     * @var \Phergie\Irc\Bot\React\EventQueueInterface
     */
    protected $queue;

    /**
     * Mock connection
     *
     * @var \Phergie\Irc\ConnectionInterface
     */
    protected $connection;

    /**
     * Create common object instances.
     */
    protected function setUp()
    {
        $this->logger = Phake::mock('\Psr\Log\LoggerInterface');
        $this->queue = Phake::mock('\Phergie\Irc\Bot\React\EventQueueInterface');
        $this->connection = Phake::mock('\Phergie\Irc\ConnectionInterface');
        Phake::when($this->connection)->getNickname()->thenReturn('BotNick');
    }

    /**
     * Returns an instance of the plugin under test.
     *
     * @param bool $loadDummyMaps Whether to load dummy chanmode/prefix maps
     * @return \Phergie\Irc\Plugin\React\UserMode
     */
    protected function getPlugin($loadDummyMaps)
    {
        $plugin = new Plugin;
        $plugin->setLogger($this->logger);
        if ($loadDummyMaps) {
            $store = $this->getConnectionStoreObject($plugin);
            $store[$this->connection] = new \ArrayObject(array(
                'modes' => array(
                    'a' => Plugin::CHANMODE_TYPE_LIST,
                    'b' => Plugin::CHANMODE_TYPE_LIST,
                    'c' => Plugin::CHANMODE_TYPE_PARAM_ALWAYS,
                    'd' => Plugin::CHANMODE_TYPE_PARAM_ALWAYS,
                    'e' => Plugin::CHANMODE_TYPE_PARAM_ALWAYS,
                    'f' => Plugin::CHANMODE_TYPE_PARAM_ALWAYS,
                    'g' => Plugin::CHANMODE_TYPE_PARAM_SETONLY,
                    'h' => Plugin::CHANMODE_TYPE_PARAM_SETONLY,
                    'i' => Plugin::CHANMODE_TYPE_NOPARAM,
                    'j' => Plugin::CHANMODE_TYPE_NOPARAM,
                ),
                'prefixes' => array('%' => 'e', '&' => 'f'),
            ));
        }
        return $plugin;
    }

    /**
     * Returns a plugin instance with a pre-filled channel store.
     *
     * @return \Phergie\Irc\Plugin\React\UserMode
     */
    protected function getPluginPrePopulated()
    {
        $plugin = $this->getPlugin(true);
        $lists = $this->getChannelListStoreObject($plugin);
        $lists[$this->connection] = new \ArrayObject(array(
            '#channel1' => array(
                'user1' => array(
                    'e' => true,
                    'f' => true,
                ),
                'user2' => array(),
                'user3' => array(),
            ),
            '#channel2' => array(
                'user1' => array(
                    'f' => true,
                ),
                'user2' => array(
                    'e' => true,
                ),
                'user4' => array(),
            ),
            '#channel3' => array(
                'user4' => array(
                    'e' => true,
                ),
                'user5' => array(),
                'user6' => array(),
            ),
        ));
        return $plugin;
    }

    /**
     * Returns a reference to the plugin's internal connection store.
     *
     * @param \Phergie\Irc\Plugin\React\UserMode $plugin
     * @return \SplObjectStorage
     */
    protected function getConnectionStoreObject(Plugin $plugin)
    {
        $reflector = new \ReflectionObject($plugin);
        $property = $reflector->getProperty('connectionStore');
        $property->setAccessible(true);
        return $property->getValue($plugin);
    }

    /**
     * Returns a reference to the plugin's internal channel list store.
     *
     * @param \Phergie\Irc\Plugin\React\UserMode $plugin
     * @return \SplObjectStorage
     */
    protected function getChannelListStoreObject(Plugin $plugin)
    {
        $reflector = new \ReflectionObject($plugin);
        $property = $reflector->getProperty('channelLists');
        $property->setAccessible(true);
        return $property->getValue($plugin);
    }

    /**
     * Data provider for testPluginConfig
     *
     * @return array
     */
    public function dataProviderPluginConfig()
    {
        return array(
            // No configuration
            array(
                array(),
                null,
                null,
            ),

            // Channel mode map
            array(
                array('defaultmodetypes' => array('a' => Plugin::CHANMODE_TYPE_NOPARAM, 'b' => Plugin::CHANMODE_TYPE_NOPARAM)),
                null,
                null,
            ),

            array(
                array('defaultmodetypes' => 'ab'),
                'Configuration option "defaultmodetypes" must be of type "array"',
                Plugin::ERR_CONFIG_MODETYPES_INVALID,
            ),

            array(
                array('defaultmodetypes' => array('ab' => Plugin::CHANMODE_TYPE_NOPARAM)),
                'The default mode type map provided is invalid',
                Plugin::ERR_CONFIG_MODETYPES_INVALID,
            ),

            // Prefix map
            array(
                array('defaultprefixes' => array('!' => 'a', '%' => 'b', '&' => 'c')),
                null,
                null,
            ),

            array(
                array('defaultprefixes' => 'abc'),
                'Configuration option "defaultprefixes" must be of type "array"',
                Plugin::ERR_CONFIG_PREFIXES_INVALID,
            ),

            array(
                array('defaultprefixes' => array('abc')),
                'The default prefix map provided is invalid',
                Plugin::ERR_CONFIG_PREFIXES_INVALID,
            ),
        );
    }

    /**
     * Tests plugin initialisation.
     *
     * @param array $config
     * @param string|null $errmsg
     * @param int|null $errcode
     * @dataProvider dataProviderPluginConfig
     */
    public function testPluginConfig(array $config, $errmsg, $errcode)
    {
        if ($errmsg !== null) {
            try {
                $plugin = new Plugin($config);
                $this->fail('Expected exception was not thrown');
            } catch (\DomainException $e) {
                $this->assertSame($errmsg, $e->getMessage());
                $this->assertSame($errcode, $e->getCode());
            }
        } else {
            $plugin = new Plugin($config);
        }
    }

    /**
     * Tests that getSubscribedEvents() returns an array.
     */
    public function testGetSubscribedEvents()
    {
        $plugin = new Plugin;
        $this->assertInternalType('array', $plugin->getSubscribedEvents());
    }

    /**
     * Test RPL_ISUPPORT parsing for chanmode types and prefixes.
     */
    public function testParseMaps()
    {
        $plugin = new Plugin;
        $plugin->setLogger($this->logger);
        $event = Phake::mock('\Phergie\Irc\Event\ServerEventInterface');
        $params = array(
            'CHANMODES=ab,,c,d',
            'PREFIX=(ef)!%',
        );
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        Phake::when($event)->getParams()->thenReturn($params);
        $plugin->processCapabilities($event, $this->queue);

        Phake::verify($this->logger)->debug('Parsing chanmode types from RPL_ISUPPORT');
        Phake::verify($this->logger)->debug('Parsing prefixes from RPL_ISUPPORT');

        $store = $this->getConnectionStoreObject($plugin);
        $this->assertTrue($store->contains($this->connection));
        $this->assertEquals(array(
            'a' => Plugin::CHANMODE_TYPE_LIST,
            'b' => Plugin::CHANMODE_TYPE_LIST,
            'c' => Plugin::CHANMODE_TYPE_PARAM_SETONLY,
            'd' => Plugin::CHANMODE_TYPE_NOPARAM,
            'e' => Plugin::CHANMODE_TYPE_PARAM_ALWAYS,
            'f' => Plugin::CHANMODE_TYPE_PARAM_ALWAYS,
        ), $store[$this->connection]['modes']);
        $this->assertEquals(array('!' => 'e', '%' => 'f'), $store[$this->connection]['prefixes']);
    }

    /**
     * Tests RPL_ISUPPORT parsing for the NAMESX capability.
     */
    public function testNamesX()
    {
        $plugin = new Plugin;
        $plugin->setLogger($this->logger);
        $event = Phake::mock('\Phergie\Irc\Event\ServerEventInterface');
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        Phake::when($event)->getParams()->thenReturn(array('NAMESX'));
        $plugin->processCapabilities($event, $this->queue);

        Phake::verify($this->queue)->ircProtoctl('NAMESX');
    }

    /**
     * Data provider for testGetChannelModeType
     *
     * @return array
     */
    public function dataProviderGetChannelModeType()
    {
        return array(
            // With dummy map
            array('a', Plugin::CHANMODE_TYPE_LIST, true),
            array('c', Plugin::CHANMODE_TYPE_PARAM_ALWAYS, true),
            array('e', Plugin::CHANMODE_TYPE_PARAM_ALWAYS, true),
            array('g', Plugin::CHANMODE_TYPE_PARAM_SETONLY, true),
            array('i', Plugin::CHANMODE_TYPE_NOPARAM, true),
            array('z', false, true),

            // With default map
            array('b', Plugin::CHANMODE_TYPE_LIST, false),
            array('k', Plugin::CHANMODE_TYPE_PARAM_ALWAYS, false),
            array('h', Plugin::CHANMODE_TYPE_PARAM_ALWAYS, false),
            array('l', Plugin::CHANMODE_TYPE_PARAM_SETONLY, false),
            array('i', Plugin::CHANMODE_TYPE_NOPARAM, false),
            array('z', false, false),

            // Invalid arguments
            array(null, false, false),
            array('', false, false),
            array('string', false, false),
        );
    }

    /**
     * Tests getChannelModeType.
     *
     * @param string $mode
     * @param int $expected
     * @param bool $loadDummyMaps
     * @dataProvider dataProviderGetChannelModeType
     */
    public function testGetChannelModeType($mode, $expected, $loadDummyMaps)
    {
        $plugin = $this->getPlugin($loadDummyMaps);
        $this->assertSame($expected, $plugin->getChannelModeType($this->connection, $mode));
    }

    /**
     * Tests getChannelModeByType with invalid parameter.
     */
    public function testGetChannelModeTypeInvalid()
    {
        $plugin = $this->getPlugin(false);
        $this->assertSame(false, $plugin->getChannelModeType($this->connection, 'string'));
        Phake::verify($this->logger)->warning($this->stringContains('invalid argument'), $this->anything());
    }

    /**
     * Data provider for testGetPrefixFromChannelMode
     *
     * @return array
     */
    public function dataProviderGetPrefixFromChannelMode()
    {
        return array(
            // With dummy map
            array('e', '%', true),
            array('f', '&', true),
            array('c', false, true),

            // With default map
            array('h', '%', false),
            array('o', '@', false),
            array('k', false, false),
        );
    }

    /**
     * Tests getPrefixFromChannelMode.
     *
     * @param string $mode
     * @param string $expected
     * @param bool $loadDummyMaps
     * @dataProvider dataProviderGetPrefixFromChannelMode
     */
    public function testGetPrefixFromChannelMode($mode, $expected, $loadDummyMaps)
    {
        $plugin = $this->getPlugin($loadDummyMaps);
        $this->assertSame($expected, $plugin->getPrefixFromChannelMode($this->connection, $mode));
    }

    /**
     * Tests getPrefixFromChannelMode with invalid parameter.
     */
    public function testGetPrefixFromChannelModeInvalid()
    {
        $plugin = $this->getPlugin(false);
        $this->assertSame(false, $plugin->getPrefixFromChannelMode($this->connection, 'string'));
        Phake::verify($this->logger)->warning($this->stringContains('invalid argument'), $this->anything());
    }

    /**
     * Data provider for testGetChannelModeFromPrefix
     *
     * @return array
     */
    public function dataProviderGetChannelModeFromPrefix()
    {
        return array(
            // With dummy map
            array('%', 'e', true),
            array('&', 'f', true),
            array('*', false, true),

            // With default map
            array('%', 'h', false),
            array('@', 'o', false),
            array('*', false, false),
        );
    }

    /**
     * Tests getChannelModeFromPrefix.
     *
     * @param string $prefix
     * @param string $expected
     * @param bool $loadDummyMaps
     * @dataProvider dataProviderGetChannelModeFromPrefix
     */
    public function testGetChannelModeFromPrefix($prefix, $expected, $loadDummyMaps)
    {
        $plugin = $this->getPlugin($loadDummyMaps);
        $this->assertSame($expected, $plugin->getChannelModeFromPrefix($this->connection, $prefix));
    }

    /**
     * Tests getChannelModeFromPrefix with invalid parameter.
     */
    public function testGetChannelModeFromPrefixInvalid()
    {
        $plugin = $this->getPlugin(false);
        $this->assertSame(false, $plugin->getChannelModeFromPrefix($this->connection, 'string'));
        Phake::verify($this->logger)->warning($this->stringContains('invalid argument'), $this->anything());
    }

    /**
     * Tests getPrefixMap.
     */
    public function testPrefixMap()
    {
        $plugin = $this->getPlugin(false);
        $this->assertEquals(array('@' => 'o', '%' => 'h', '+' => 'v'), $plugin->getPrefixMap($this->connection));

        $plugin = $this->getPlugin(true);
        $this->assertEquals(array('%' => 'e', '&' => 'f'), $plugin->getPrefixMap($this->connection));
    }

    /**
     * Data provider for testParseChannelModeChange
     *
     * @return array
     */
    public function dataProviderParseChannelModeChange()
    {
        return array(
            // Single mode changes, with dummy map
            array(
                '+a',
                'param1',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'a',
                        'param' => 'param1',
                    ),
                ),
                true,
            ),

            array(
                '-b',
                'param1',
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'b',
                        'param' => 'param1',
                    ),
                ),
                true,
            ),

            array(
                '+c',
                'param1',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'c',
                        'param' => 'param1',
                    ),
                ),
                true,
            ),

            array(
                '-d',
                'param1',
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'd',
                        'param' => 'param1',
                    ),
                ),
                true,
            ),

            array(
                '+e',
                'user1',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'e',
                        'prefix' => '%',
                        'param' => 'user1',
                    ),
                ),
                true,
            ),

            array(
                '-f',
                'user1',
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'f',
                        'prefix' => '&',
                        'param' => 'user1',
                    ),
                ),
                true,
            ),

            array(
                '+g',
                'param1',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'g',
                        'param' => 'param1',
                    ),
                ),
                true,
            ),

            array(
                '-h',
                null,
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'h',
                    ),
                ),
                true,
            ),

            array(
                '+i',
                null,
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'i',
                    ),
                ),
                true,
            ),

            array(
                '-j',
                null,
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'j',
                    ),
                ),
                true,
            ),

            // Single mode changes, without dummy map
            array(
                '+b',
                'param1',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'b',
                        'param' => 'param1',
                    ),
                ),
                false,
            ),

            array(
                '-e',
                'param1',
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'e',
                        'param' => 'param1',
                    ),
                ),
                false,
            ),

            array(
                '+k',
                'param1',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'k',
                        'param' => 'param1',
                    ),
                ),
                false,
            ),

            array(
                '-k',
                'param1',
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'k',
                        'param' => 'param1',
                    ),
                ),
                false,
            ),

            array(
                '+h',
                'user1',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'h',
                        'prefix' => '%',
                        'param' => 'user1',
                    ),
                ),
                false,
            ),

            array(
                '-o',
                'user1',
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'o',
                        'prefix' => '@',
                        'param' => 'user1',
                    ),
                ),
                false,
            ),

            array(
                '+l',
                'param1',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'l',
                        'param' => 'param1',
                    ),
                ),
                false,
            ),

            array(
                '-l',
                null,
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'l',
                    ),
                ),
                false,
            ),

            array(
                '+i',
                null,
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'i',
                    ),
                ),
                false,
            ),

            array(
                '-m',
                null,
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'm',
                    ),
                ),
                false,
            ),

            // List request
            array(
                'b',
                null,
                array(
                    array(
                        'mode' => 'b',
                    ),
                ),
                true,
            ),

            array(
                'a+b',
                null,
                array(
                    array(
                        'mode' => 'a',
                    ),
                    array(
                        'mode' => 'b',
                    ),
                ),
                true,
            ),

            // Multiple modes, same type
            array(
                '+ab',
                'param1 param2',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'a',
                        'param' => 'param1',
                    ),
                    array(
                        'operation' => '+',
                        'mode' => 'b',
                        'param' => 'param2',
                    ),
                ),
                true,
            ),

            array(
                '-cd',
                'param1 param2',
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'c',
                        'param' => 'param1',
                    ),
                    array(
                        'operation' => '-',
                        'mode' => 'd',
                        'param' => 'param2',
                    ),
                ),
                true,
            ),

            array(
                '+ef',
                'user1 user2',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'e',
                        'prefix' => '%',
                        'param' => 'user1',
                    ),
                    array(
                        'operation' => '+',
                        'mode' => 'f',
                        'prefix' => '&',
                        'param' => 'user2',
                    ),
                ),
                true,
            ),

            array(
                '-gh',
                null,
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'g',
                    ),
                    array(
                        'operation' => '-',
                        'mode' => 'h',
                    ),
                ),
                true,
            ),

            array(
                '+ij',
                null,
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'i',
                    ),
                    array(
                        'operation' => '+',
                        'mode' => 'j',
                    ),
                ),
                true,
            ),

            // Multiple modes, different types
            array(
                '-abcd',
                'param1 param2 param3 param4',
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'a',
                        'param' => 'param1',
                    ),
                    array(
                        'operation' => '-',
                        'mode' => 'b',
                        'param' => 'param2',
                    ),
                    array(
                        'operation' => '-',
                        'mode' => 'c',
                        'param' => 'param3',
                    ),
                    array(
                        'operation' => '-',
                        'mode' => 'd',
                        'param' => 'param4',
                    ),
                ),
                true,
            ),

            array(
                '+efghij',
                'user1 user2 param3 param4',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'e',
                        'prefix' => '%',
                        'param' => 'user1',
                    ),
                    array(
                        'operation' => '+',
                        'mode' => 'f',
                        'prefix' => '&',
                        'param' => 'user2',
                    ),
                    array(
                        'operation' => '+',
                        'mode' => 'g',
                        'param' => 'param3',
                    ),
                    array(
                        'operation' => '+',
                        'mode' => 'h',
                        'param' => 'param4',
                    ),
                    array(
                        'operation' => '+',
                        'mode' => 'i',
                    ),
                    array(
                        'operation' => '+',
                        'mode' => 'j',
                    ),
                ),
                true,
            ),

            // Mix of operations
            array(
                '-i+j',
                null,
                array(
                    array(
                        'operation' => '-',
                        'mode' => 'i',
                    ),
                    array(
                        'operation' => '+',
                        'mode' => 'j',
                    ),
                ),
                true,
            ),

            array(
                '+ai-ceg',
                'param1 param2 user3',
                array(
                    array(
                        'operation' => '+',
                        'mode' => 'a',
                        'param' => 'param1',
                    ),
                    array(
                        'operation' => '+',
                        'mode' => 'i',
                    ),
                    array(
                        'operation' => '-',
                        'mode' => 'c',
                        'param' => 'param2',
                    ),
                    array(
                        'operation' => '-',
                        'mode' => 'e',
                        'prefix' => '%',
                        'param' => 'user3',
                    ),
                    array(
                        'operation' => '-',
                        'mode' => 'g',
                    ),
                ),
                true,
            ),
        );
    }

    /**
     * Tests parseChannelModeChange.
     *
     * @param string $modes
     * @param string|null $params
     * @param array $expected
     * @param bool $loadDummyMaps
     * @dataProvider dataProviderParseChannelModeChange
     */
    public function testParseChannelModeChange($modes, $params, array $expected, $loadDummyMaps)
    {
        $plugin = $this->getPlugin($loadDummyMaps);
        $this->assertEquals($expected, $plugin->parseChannelModeChange($this->connection, $modes, $params));
    }

    /**
     * Data provider for testParseChannelModeChangeInvalid
     *
     * @return array
     */
    public function dataProviderParseChannelModeChangeInvalid()
    {
        return array(
            // No operator (and not a list mode)
            array(
                'm',
                null,
                'no operation found',
            ),

            // Unknown mode
            array(
                '+z',
                null,
                'chanmode z not recognised',
            ),

            // Not enough trailing params
            array(
                '+o',
                null,
                'not enough params',
            ),

            array(
                '+l',
                null,
                'not enough params',
            ),

            // Too many trailing params
            array(
                '-l',
                'param1',
                'too many params',
            ),

            array(
                '-i',
                'param1',
                'too many params',
            ),
        );
    }

    /**
     * Tests parseChannelModeChange with invalid parameters.
     *
     * @param string $modes
     * @param string|null $params
     * @param string $warning
     * @dataProvider dataProviderParseChannelModeChangeInvalid
     */
    public function testParseChannelModeChangeInvalid($modes, $params, $warning)
    {
        $plugin = $this->getPlugin(false);
        $this->assertSame(array(), $plugin->parseChannelModeChange($this->connection, $modes, $params));
        Phake::verify($this->logger)->warning($this->stringContains($warning), $this->anything());
    }

    /**
     * Tests parseChannelModeChange with a mode that's in the store, but not a known type.
     * If you somehow manage to make this happen, kudos.
     */
    public function testParseChannelModeChangeInsane()
    {
        $plugin = $this->getPlugin(false);
        $store = $this->getConnectionStoreObject($plugin);
        $store[$this->connection] = new \ArrayObject(array('modes' => array('z' => -1)));
        $this->assertSame(array(), $plugin->parseChannelModeChange($this->connection, '+z', null));
        Phake::verify($this->logger)->warning($this->stringContains('corrupted mode store'), $this->anything());
    }

    /**
     * Tests adding a prefix mode.
     */
    public function testAddPrefixMode()
    {
        $plugin = $this->getPlugin(true);
        $lists = $this->getChannelListStoreObject($plugin);
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channel' => '#channel', 'mode' => '+e', 'params' => 'user1'));
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->changePrefixModes($event, $this->queue);

        Phake::verify($this->logger)->debug('Adding user mode', array(
            'channel' => '#channel',
            'nick' => 'user1',
            'mode' => 'e',
            'prefix' => '%',
        ));
        $this->assertTrue($lists->contains($this->connection));
        $this->assertArrayHasKey('e', $lists[$this->connection]['#channel']['user1']);
    }

    /**
     * Tests removing a prefix mode.
     */
    public function testRemovePrefixMode()
    {
        $plugin = $this->getPlugin(true);
        $lists = $this->getChannelListStoreObject($plugin);
        $lists[$this->connection] = new \ArrayObject(array('#channel' => array('user2' => array('f' => true))));
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channel' => '#channel', 'mode' => '-f', 'params' => 'user2'));
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->changePrefixModes($event, $this->queue);

        Phake::verify($this->logger)->debug('Removing user mode', array(
            'channel' => '#channel',
            'nick' => 'user2',
            'mode' => 'f',
            'prefix' => '&',
        ));
        $this->assertTrue($lists->contains($this->connection));
        $this->assertArrayNotHasKey('f', $lists[$this->connection]['#channel']['user2']);
    }

    /**
     * Tests a compound prefix mode change.
     */
    public function testCompoundPrefixModeChange()
    {
        $plugin = $this->getPlugin(true);
        $lists = $this->getChannelListStoreObject($plugin);
        $lists[$this->connection] = new \ArrayObject(array('#channel' => array('user3' => array('e' => true))));
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channel' => '#channel', 'mode' => '-eg+acf', 'params' => 'user3 param4 param5 user6'));
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->changePrefixModes($event, $this->queue);

        Phake::verify($this->logger)->debug('Removing user mode', array(
            'channel' => '#channel',
            'nick' => 'user3',
            'mode' => 'e',
            'prefix' => '%',
        ));
        Phake::verify($this->logger)->debug('Adding user mode', array(
            'channel' => '#channel',
            'nick' => 'user6',
            'mode' => 'f',
            'prefix' => '&',
        ));
        $this->assertTrue($lists->contains($this->connection));
        $this->assertArrayNotHasKey('e', $lists[$this->connection]['#channel']['user3']);
        $this->assertArrayHasKey('f', $lists[$this->connection]['#channel']['user6']);
    }

    /**
     * Tests an unparsable channel mode change.
     */
    public function testNonsenseModeChange()
    {
        $plugin = $this->getPlugin(true);
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channel' => '#channel', 'mode' => '+z', 'params' => 'nonsense'));
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->changePrefixModes($event, $this->queue);

        Phake::verify($this->logger)->debug('Could not parse mode change, refreshing prefixes');
        Phake::verify($this->queue)->ircNames('#channel');
    }

    /**
     * Tests addUserToChannel.
     */
    public function testAddUserToChannel()
    {
        $plugin = $this->getPlugin(false);
        $lists = $this->getChannelListStoreObject($plugin);
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channels' => '#channel'));
        Phake::when($event)->getNick()->thenReturn('user1');
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->addUserToChannel($event, $this->queue);

        Phake::verify($this->logger)->debug('Adding user to channel', array('channel' => '#channel', 'nick' => 'user1'));
        $this->assertTrue($lists->contains($this->connection));
        $this->assertArrayHasKey('user1', $lists[$this->connection]['#channel']);
    }

    /**
     * Tests removeUserFromChannel.
     */
    public function testRemoveUserFromChannel()
    {
        $plugin = $this->getPlugin(false);
        $lists = $this->getChannelListStoreObject($plugin);
        $lists[$this->connection] = new \ArrayObject(array('#channel' => array('user2' => array())));
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channels' => '#channel'));
        Phake::when($event)->getNick()->thenReturn('user2');
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->removeUserFromChannel($event, $this->queue);

        Phake::verify($this->logger)->debug('Removing user mode data', array('channel' => '#channel', 'nick' => 'user2'));
        $this->assertArrayNotHasKey('user2', $lists[$this->connection]['#channel']);
    }

    /**
     * Tests removing the bot from a channel.
     */
    public function testRemoveBotFromChannel()
    {
        $plugin = $this->getPlugin(false);
        $lists = $this->getChannelListStoreObject($plugin);
        $lists[$this->connection] = new \ArrayObject(array('#channel' => array('user1' => array(), 'user2' => array())));
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channels' => '#channel'));
        Phake::when($event)->getNick()->thenReturn('BotNick');
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->removeUserFromChannel($event, $this->queue);

        Phake::verify($this->logger)->debug('Deleting all modes for channel', array('channel' => '#channel'));
        $this->assertArrayNotHasKey('#channel', $lists[$this->connection]);
    }

    /**
     * Tests removeKickedUserFromChannel.
     */
    public function testRemoveKickedUserFromChannel()
    {
        $plugin = $this->getPlugin(false);
        $lists = $this->getChannelListStoreObject($plugin);
        $lists[$this->connection] = new \ArrayObject(array('#channel' => array('user3' => array())));
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channel' => '#channel', 'user' => 'user3'));
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->removeKickedUserFromChannel($event, $this->queue);

        Phake::verify($this->logger)->debug('Removing user mode data', array('channel' => '#channel', 'nick' => 'user3'));
        $this->assertArrayNotHasKey('user3', $lists[$this->connection]['#channel']);
    }

    /**
     * Tests removing the bot from a channel after being kicked.
     */
    public function testKickBotFromChannel()
    {
        $plugin = $this->getPlugin(false);
        $lists = $this->getChannelListStoreObject($plugin);
        $lists[$this->connection] = new \ArrayObject(array('#channel' => array('user1' => array(), 'user2' => array())));
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channel' => '#channel', 'user' => 'BotNick'));
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->removeKickedUserFromChannel($event, $this->queue);

        Phake::verify($this->logger)->debug('Deleting all modes for channel', array('channel' => '#channel'));
        $this->assertArrayNotHasKey('#channel', $lists[$this->connection]);
    }

    /**
     * Tests removeUser.
     */
    public function testRemoveUser()
    {
        $plugin = $this->getPlugin(false);
        $lists = $this->getChannelListStoreObject($plugin);
        $lists[$this->connection] = new \ArrayObject(array(
            '#channel1' => array('user1' => array()),
            '#channel2' => array('user1' => array()),
        ));
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getNick()->thenReturn('user1');
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->removeUser($event, $this->queue);

        Phake::verify($this->logger)->debug('Removing user mode data', array('channel' => '#channel1', 'nick' => 'user1'));
        Phake::verify($this->logger)->debug('Removing user mode data', array('channel' => '#channel2', 'nick' => 'user1'));
        $this->assertArrayNotHasKey('user1', $lists[$this->connection]['#channel1']);
        $this->assertArrayNotHasKey('user1', $lists[$this->connection]['#channel2']);
    }

    /**
     * Tests clearing up channel lists on closing bot connection.
     */
    public function testRemoveBot()
    {
        $plugin = $this->getPlugin(false);
        $lists = $this->getChannelListStoreObject($plugin);
        $lists[$this->connection] = new \ArrayObject(array(
            '#channel1' => array('user1' => array()),
            '#channel2' => array('user2' => array()),
        ));
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getNick()->thenReturn('BotNick');
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->removeUser($event, $this->queue);

        Phake::verify($this->logger)->debug('Deleting all modes for connection');
        $this->assertFalse($lists->contains($this->connection));
    }

    /**
     * Tests changeUserNick.
     */
    public function testChangeUserNick()
    {
        $plugin = $this->getPlugin(false);
        $lists = $this->getChannelListStoreObject($plugin);
        $lists[$this->connection] = new \ArrayObject(array('#channel' => array('user1' => array())));
        $event = Phake::mock('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('nickname' => 'user2'));
        Phake::when($event)->getNick()->thenReturn('user1');
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->changeUserNick($event, $this->queue);

        Phake::verify($this->logger)->debug('Moving user mode data', array('channel' => '#channel', 'oldNick' => 'user1', 'newNick' => 'user2'));
        $this->assertArrayNotHasKey('user1', $lists[$this->connection]['#channel']);
        $this->assertArrayHasKey('user2', $lists[$this->connection]['#channel']);
    }

    /**
     * Data provider for testLoadChannelList
     *
     * return @array
     */
    public function dataProviderLoadChannelList()
    {
        return array(
            // No prefixes
            array(
                ['user1', 'user2', 'user3'],
                array(
                    'user1' => [],
                    'user2' => [],
                    'user3' => [],
                ),
            ),

            // Prefixes
            array(
                ['user1', '%user2', '&user3'],
                array(
                    'user1' => [],
                    'user2' => ['e' => true],
                    'user3' => ['f' => true],
                ),
            ),

            // Trailing params
            array(
                ['%user1 &user2 user3'],
                array(
                    'user1' => ['e' => true],
                    'user2' => ['f' => true],
                    'user3' => [],
                ),
            ),
        );
    }

    /**
     * Tests loadChannelList.
     *
     * @param array $names
     * @param array $expected
     * @dataProvider dataProviderLoadChannelList
     */
    public function testLoadChannelList(array $names, array $expected)
    {
        $plugin = $this->getPlugin(true);
        $lists = $this->getChannelListStoreObject($plugin);
        $params = array_merge(array('=', '#channel'), $names);
        $event = Phake::mock('\Phergie\Irc\Event\ServerEventInterface');
        Phake::when($event)->getParams()->thenReturn($params);
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        $plugin->loadChannelList($event, $this->queue);

        foreach ($expected as $nick => $modes) {
            Phake::verify($this->logger)->debug('Adding user to channel', array('channel' => '#channel', 'nick' => $nick));
            foreach (array_keys($modes) as $mode) {
                Phake::verify($this->logger)->debug('Recording user mode', array('channel' => '#channel', 'nick' => $nick, 'mode' => $mode));
            }
        }
        $this->assertTrue($lists->contains($this->connection));
        $this->assertEquals($expected, $lists[$this->connection]['#channel']);
    }

    /**
     * Tests userHasPrefixMode.
     */
    public function testUserHasPrefixMode()
    {
        $plugin = $this->getPluginPrePopulated();
        $this->assertFalse($plugin->userHasPrefixMode(Phake::mock('\Phergie\Irc\ConnectionInterface'), '#channel1', 'user1', 'e'));
        $this->assertFalse($plugin->userHasPrefixMode($this->connection, '#null', 'user1', 'e'));
        $this->assertFalse($plugin->userHasPrefixMode($this->connection, '#channel1', 'null', 'e'));
        $this->assertTrue($plugin->userHasPrefixMode($this->connection, '#channel1', 'user1', 'e'));
        $this->assertFalse($plugin->userHasPrefixMode($this->connection, '#channel1', 'user2', 'e'));
    }

    /**
     * Tests getUserPrefixModes.
     */
    public function testGetUserPrefixModes()
    {
        $plugin = $this->getPluginPrePopulated();
        $this->assertEmpty($plugin->getUserPrefixModes(Phake::mock('\Phergie\Irc\ConnectionInterface'), '#channel1', 'user1'));
        $this->assertEmpty($plugin->getUserPrefixModes($this->connection, '#null', 'user1'));
        $this->assertEmpty($plugin->getUserPrefixModes($this->connection, '#channel1', 'null'));
        $this->assertEquals(['e', 'f'], $plugin->getUserPrefixModes($this->connection, '#channel1', 'user1'));
        $this->assertEmpty($plugin->getUserPrefixModes($this->connection, '#channel1', 'user2'));
    }

    /**
     * Tests isUserInChannel.
     */
    public function testIsUserInChannel()
    {
        $plugin = $this->getPluginPrePopulated();
        $this->assertFalse($plugin->isUserInChannel(Phake::mock('\Phergie\Irc\ConnectionInterface'), '#channel2', 'user2'));
        $this->assertFalse($plugin->isUserInChannel($this->connection, '#null', 'user2'));
        $this->assertFalse($plugin->isUserInChannel($this->connection, '#channel2', 'null'));
        $this->assertTrue($plugin->isUserInChannel($this->connection, '#channel2', 'user2'));
        $this->assertFalse($plugin->isUserInChannel($this->connection, '#channel2', 'user3'));
    }

    /**
     * Tests getChannelUsers.
     */
    public function testGetChannelUsers()
    {
        $plugin = $this->getPluginPrePopulated();
        $this->assertEmpty($plugin->getChannelUsers(Phake::mock('\Phergie\Irc\ConnectionInterface'), '#channel3'));
        $this->assertEmpty($plugin->getChannelUsers($this->connection, '#null'));
        $this->assertEquals(['user4', 'user5', 'user6'], $plugin->getChannelUsers($this->connection, '#channel3'));
    }

    /**
     * Tests getUserChannels.
     */
    public function testGetUserChannels()
    {
        $plugin = $this->getPluginPrePopulated();
        $this->assertEmpty($plugin->getUserChannels(Phake::mock('\Phergie\Irc\ConnectionInterface'), 'user4'));
        $this->assertEmpty($plugin->getUserChannels($this->connection, 'null'));
        $this->assertEquals(['#channel2', '#channel3'], $plugin->getUserChannels($this->connection, 'user4'));
    }
}
