<?php

namespace Rox\E2E;

use Kevinrob\GuzzleCache\Storage\VolatileRuntimeStorage;
use Rox\Core\Configuration\ConfigurationFetchedArgs;
use Rox\Core\Configuration\FetcherStatus;
use Rox\Core\Consts\Environment;
use Rox\Core\Context\ContextBuilder;
use Rox\Core\Impression\ImpressionArgs;
use Rox\Core\Logging\LoggerFactory;
use Rox\Core\Logging\TestLoggerFactory;
use Rox\Core\Register\TestContainer;
use Rox\RoxTestCase;
use Rox\Server\Rox;
use Rox\Server\RoxOptions;
use Rox\Server\RoxOptionsBuilder;

class RoxE2ETests extends RoxTestCase
{
    /**
     * @var TestLoggerFactory $_staticLoggerFactory
     */
    private static $_staticLoggerFactory;

    public static function setUpBeforeClass()
    {
        $_ENV[Environment::ENV_VAR_NAME] = Environment::QA;

        self::$_staticLoggerFactory = new TestLoggerFactory();
        LoggerFactory::setup(self::$_staticLoggerFactory);

        $options = new RoxOptions((new RoxOptionsBuilder())
            ->setConfigurationFetchedHandler(function (ConfigurationFetchedArgs $args) {
                if ($args != null && $args->getFetcherStatus() == FetcherStatus::AppliedFromNetwork) {
                    TestVars::$configurationFetchedCount++;
                }
            })
            ->setImpressionHandler(function (ImpressionArgs $args) {
                if ($args != null && $args->getReportingValue() != null) {
                    if ($args->getReportingValue()->getName() == "flagForImpression") {
                        TestVars::$isImpressionRaised = true;
                    }
                }
                TestVars::$impressionReturnedArgs = $args;
            })
            ->setDevModeKey("ba9bf259159cfd1af16feb19")
            ->setCacheStorage(new VolatileRuntimeStorage())
            ->setLogCacheHitsAndMisses(true));

        Rox::register("", Container::getInstance());
        TestCustomPropsCreator::createCustomProps();

        Rox::setup("5df8d5e802e23378643705bf", $options);
    }

    protected function setUp()
    {
        parent::setUp();

        $this->expectNoErrors();
        $this->expectNoWarnings();
    }

    public function testSimpleFlag()
    {
        $this->assertTrue(Container::getInstance()->simpleFlag->isEnabled());
    }

    public function testSimpleFlagOverwritten()
    {
        $this->assertFalse(Container::getInstance()->simpleFlagOverwritten->isEnabled());
    }

    public function testVariant()
    {
        $this->assertEquals(Container::getInstance()->variant->getValue(), "red");
    }

    public function testVariantOverwritten()
    {
        $this->assertEquals(Container::getInstance()->variantOverwritten->getValue(), "green");
    }

    public function testAllCustomProperties()
    {
        $this->assertTrue(Container::getInstance()->flagCustomProperties->isEnabled());

        $this->assertTrue(TestVars::$isComputedBooleanPropCalled);
        $this->assertTrue(TestVars::$isComputedDoublePropCalled);
        $this->assertTrue(TestVars::$isComputedIntPropCalled);
        $this->assertTrue(TestVars::$isComputedSemverPropCalled);
        $this->assertTrue(TestVars::$isComputedStringPropCalled);
    }

    public function testFetchWithinTimeout()
    {
        $numberOfConfigFetches = TestVars::$configurationFetchedCount;

        $time = time();
        Rox::fetch();
        $secondsPassed = time() - $time;

        $this->assertTrue($secondsPassed <= 5);
        $this->assertTrue($numberOfConfigFetches < TestVars::$configurationFetchedCount);
    }

    public function testVariantWithContext()
    {
        $somePositiveContext = (new ContextBuilder())->build([
            "isDuckAndCover" => true
        ]);

        $someNegativeContext = (new ContextBuilder())->build([
            "isDuckAndCover" => false
        ]);

        $this->assertEquals(Container::getInstance()->variantWithContext->getValue(), "red");

        $this->assertEquals(Container::getInstance()->variantWithContext->getValue($somePositiveContext), "blue");
        $this->assertEquals(Container::getInstance()->variantWithContext->getValue($someNegativeContext), "red");
    }

    public function testTargetGroupsAllAnyNone()
    {
        TestVars::$targetGroup1 = TestVars::$targetGroup2 = true;

        $this->assertTrue(Container::getInstance()->flagTargetGroupsAll->isEnabled());
        $this->assertTrue(Container::getInstance()->flagTargetGroupsAny->isEnabled());
        $this->assertFalse(Container::getInstance()->flagTargetGroupsNone->isEnabled());

        TestVars::$targetGroup1 = false;
        $this->assertFalse(Container::getInstance()->flagTargetGroupsAll->isEnabled());
        $this->assertTrue(Container::getInstance()->flagTargetGroupsAny->isEnabled());
        $this->assertFalse(Container::getInstance()->flagTargetGroupsNone->isEnabled());

        TestVars::$targetGroup2 = false;
        $this->assertFalse(Container::getInstance()->flagTargetGroupsAll->isEnabled());
        $this->assertFalse(Container::getInstance()->flagTargetGroupsAny->isEnabled());
        $this->assertTrue(Container::getInstance()->flagTargetGroupsNone->isEnabled());
    }

    public function testImpressionHandler()
    {
        Container::getInstance()->flagForImpression->isEnabled();
        $this->assertTrue(TestVars::$isImpressionRaised);
        TestVars::$isImpressionRaised = false;

        $context = (new ContextBuilder())->build(["var" => "val"]);
        $flagImpressionValue = Container::getInstance()->flagForImpressionWithExperimentAndContext->isEnabled($context);
        $this->assertNotNull(TestVars::$impressionReturnedArgs);
        $this->assertNotNull(TestVars::$impressionReturnedArgs->getReportingValue());
        $this->assertEquals("true", TestVars::$impressionReturnedArgs->getReportingValue()->getValue());
        $this->assertTrue($flagImpressionValue);
        $this->assertEquals("flagForImpressionWithExperimentAndContext", TestVars::$impressionReturnedArgs->getReportingValue()->getName());

        $this->assertNotNull(TestVars::$impressionReturnedArgs);
        $this->assertNotNull(TestVars::$impressionReturnedArgs->getExperiment());
        $this->assertEquals("5df8d8b930fcc301c34ad331", TestVars::$impressionReturnedArgs->getExperiment()->getIdentifier());
        $this->assertEquals("flag for impression with experiment and context", TestVars::$impressionReturnedArgs->getExperiment()->getName());

        $this->assertEquals("val", TestVars::$impressionReturnedArgs->getContext()->get("var"));
    }

    public function testFlagDependency()
    {
        TestVars::$isPropForTargetGroupForDependency = true;
        $this->assertTrue(Container::getInstance()->flagForDependency->isEnabled());
        $this->assertFalse(Container::getInstance()->flagDependent->isEnabled());

        TestVars::$isPropForTargetGroupForDependency = false;
        $this->assertTrue(Container::getInstance()->flagDependent->isEnabled());
        $this->assertFalse(Container::getInstance()->flagForDependency->isEnabled());
    }

    public function testVariantDependencyWithContext()
    {
        $somePositiveContext = (new ContextBuilder())->build(["isDuckAndCover" => true]);

        $someNegativeContext = (new ContextBuilder())->build(["isDuckAndCover" => false]);

        $this->assertEquals("White", Container::getInstance()->flagColorDependentWithContext->getValue());
        $this->assertEquals("White", Container::getInstance()->flagColorDependentWithContext->getValue($someNegativeContext));
        $this->assertEquals("Yellow", Container::getInstance()->flagColorDependentWithContext->getValue($somePositiveContext));
    }

    public function testShouldUseCacheForConfig()
    {
        Rox::fetch();

        $this->assertTrue(self::$_staticLoggerFactory->getLogger()->hasDebugThatPasses(function ($record) {
            return strpos($record['message'], Environment::getCdnPath()) !== false &&
                strpos($record['message'], 'HIT') !== false;
        }));
    }

    public function testShouldUseCacheForSendingState()
    {
        Rox::fetch();

        $this->assertTrue(self::$_staticLoggerFactory->getLogger()->hasDebugThatPasses(function ($record) {
            return strpos($record['message'], Environment::getStateCdnPath()) !== false &&
                (strpos($record['message'], 'HIT') !== false ||
                    strpos($record['message'], 'MISS') !== false);
        }));
    }

    public function testWillNotAllowToRegisterAfterSetup()
    {
        Rox::register('', new TestContainer());
        $this->assertHasRegisterAfterSetupWarning();
    }

    public function testWillNotAllowToAddCustomBooleanPropertyAfterSetup()
    {
        Rox::setCustomBooleanProperty('test', true);
        $this->assertHasRegisterAfterSetupWarning();
    }

    public function testWillNotAllowToAddCustomDoublePropertyAfterSetup()
    {
        Rox::setCustomDoubleProperty('test', 1.0);
        $this->assertHasRegisterAfterSetupWarning();
    }

    public function testWillNotAllowToAddCustomIntegerPropertyAfterSetup()
    {
        Rox::setCustomIntegerProperty('test', 1);
        $this->assertHasRegisterAfterSetupWarning();
    }

    public function testWillNotAllowToAddCustomStringPropertyAfterSetup()
    {
        Rox::setCustomStringProperty('test', 'foo');
        $this->assertHasRegisterAfterSetupWarning();
    }

    public function testWillNotAllowToAddCustomSemverPropertyAfterSetup()
    {
        Rox::setCustomSemverProperty('test', '1.0.0');
        $this->assertHasRegisterAfterSetupWarning();
    }

    public function testWillNotAllowToAddCustomComputedBooleanPropertyAfterSetup()
    {
        Rox::setCustomComputedBooleanProperty('test', function () {
            return true;
        });
        $this->assertHasRegisterAfterSetupWarning();
    }

    public function testWillNotAllowToAddCustomComputedDoublePropertyAfterSetup()
    {
        Rox::setCustomComputedDoubleProperty('test', function () {
            return 1.0;
        });
        $this->assertHasRegisterAfterSetupWarning();
    }

    public function testWillNotAllowToAddCustomComputedIntegerPropertyAfterSetup()
    {
        Rox::setCustomComputedIntegerProperty('test', function () {
            return 1;
        });
        $this->assertHasRegisterAfterSetupWarning();
    }

    public function testWillNotAllowToAddCustomComputedStringPropertyAfterSetup()
    {
        Rox::setCustomComputedStringProperty('test', function () {
            return 'foo';
        });
        $this->assertHasRegisterAfterSetupWarning();
    }

    public function testWillNotAllowToAddCustomComputedSemverPropertyAfterSetup()
    {
        Rox::setCustomComputedSemverProperty('test', function () {
            return '1.0.0';
        });
        $this->assertHasRegisterAfterSetupWarning();
    }

    private function assertHasRegisterAfterSetupWarning()
    {
        $this->assertTrue(self::$_staticLoggerFactory->getLogger()
            ->hasWarning('Cannot register new container or add custom property after setup() is called'));
    }
}
