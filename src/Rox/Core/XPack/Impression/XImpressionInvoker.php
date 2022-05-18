<?php

namespace Rox\Core\Impression;

use Exception;
use Psr\Log\LoggerInterface;
use Rox\Core\Client\InternalFlagsInterface;
use Rox\Core\Configuration\Models\ExperimentModel;
use Rox\Core\Consts\PropertyType;
use Rox\Core\Context\ContextInterface;
use Rox\Core\CustomProperties\CustomPropertyType;
use Rox\Core\ErrorHandling\ExceptionTrigger;
use Rox\Core\ErrorHandling\UserspaceUnhandledErrorInvokerInterface;
use Rox\Core\Impression\Models\ReportingValue;
use Rox\Core\Logging\LoggerFactory;
use Rox\Core\Repositories\CustomPropertyRepositoryInterface;
use Rox\Core\XPack\Analytics\ClientInterface;
use Rox\Core\XPack\Analytics\Model\Event;

class XImpressionInvoker implements ImpressionInvokerInterface
{
    /**
     * @var LoggerInterface
     */
    private $_log;

    /**
     * @var CustomPropertyRepositoryInterface $_customPropertyRepository
     */
    private $_customPropertyRepository;

    /**
     * @var InternalFlagsInterface $_customPropertyRepository
     */
    private $_internalFlags;

    /**
     * @var ClientInterface $_analyticsClient
     */
    private $_analyticsClient;

    /**
     * @var callable[] $_eventHandlers
     */
    private $_eventHandlers = [];

    /**
     * @var UserspaceUnhandledErrorInvokerInterface $_userUnhandledErrorInvoker
     */
    protected $_userUnhandledErrorInvoker;

    /**
     * XImpressionInvoker constructor.
     * @param InternalFlagsInterface $internalFlags
     * @param CustomPropertyRepositoryInterface|null $customPropertyRepository
     * @param ClientInterface|null $analyticsClient
     */
    public function __construct(
        InternalFlagsInterface                  $internalFlags,
        UserspaceUnhandledErrorInvokerInterface $userUnhandledErrorInvoker = null,
        CustomPropertyRepositoryInterface       $customPropertyRepository = null,
        ClientInterface                         $analyticsClient = null)
    {
        $this->_log = LoggerFactory::getInstance()->createLogger(self::class);
        $this->_customPropertyRepository = $customPropertyRepository;
        $this->_userUnhandledErrorInvoker = $userUnhandledErrorInvoker;
        $this->_internalFlags = $internalFlags;
        $this->_analyticsClient = $analyticsClient;
    }

    /**
     * @param callable $handler
     */
    function register(callable $handler)
    {
        if (!in_array($handler, $this->_eventHandlers)) {
            $this->_eventHandlers[] = $handler;
        }
    }

    /**
     * @param ReportingValue $value
     * @param ExperimentModel|null $experiment
     * @param ContextInterface|null $context
     */
    function invoke(
        ReportingValue   $value,
        ExperimentModel  $experiment = null,
        ContextInterface $context = null)
    {
        try {
            $internalExperiment = $this->_internalFlags->isEnabled('rox.internal.analytics');
            if ($internalExperiment && $this->_analyticsClient) {
                $prop = $experiment ? $this->_customPropertyRepository->getCustomProperty($experiment->getStickinessProperty()) : null;
                if (!$prop) {
                    $prop = $this->_customPropertyRepository->getCustomProperty('rox.' . PropertyType::getDistinctId()->getName());
                }
                $distinctId = '(null_distinct_id';
                if ($prop != null && $prop->getType() === CustomPropertyType::getString()) {
                    $propValue = $prop->getValue();
                    $propDistinctId = $propValue($context);
                    if ($propDistinctId !== null) {
                        $distinctId = $propDistinctId;
                    }
                }
                $this->_analyticsClient->track((new Event())
                    ->setFlag($value->getName())
                    ->setValue($value->getValue())
                    ->setDistinctId($distinctId));
            }
        } catch (Exception $e) {

            $this->_log->error("Failed to send analytics", [
                'exception' => $e
            ]);
        }

        $this->_fireImpression(new ImpressionArgs($value, $context));
    }

    /**
     * @param ImpressionArgs $args
     */
    private function _fireImpression(ImpressionArgs $args)
    {
        foreach ($this->_eventHandlers as $handler) {
            try {
                $handler($args);
            } catch (Exception $e) {
                if ($this->_userUnhandledErrorInvoker) {
                    $this->_userUnhandledErrorInvoker
                        ->invoke($handler, ExceptionTrigger::ImpressionHandler, $e);
                }
            }
        }
    }
}
