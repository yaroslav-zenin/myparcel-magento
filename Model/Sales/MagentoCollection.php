<?php
/**
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Sales;

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Order\Email\Sender\TrackSender;
use MyParcelNL\Magento\Model\Source\ReturnInTheBox;
use MyParcelNL\Magento\Observer\NewShipment;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

/**
 * Class MagentoOrderCollection
 *
 * @package MyParcelNL\Magento\Model\Sales
 */
class MagentoCollection implements MagentoCollectionInterface
{
    const PATH_HELPER_DATA            = 'MyParcelNL\Magento\Helper\Data';
    const PATH_MODEL_ORDER            = '\Magento\Sales\Model\ResourceModel\Order\Collection';
    const PATH_MODEL_SHIPMENT         = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Collection';
    const PATH_ORDER_GRID             = '\Magento\Sales\Model\ResourceModel\Order\Grid\Collection';
    const PATH_ORDER_TRACK            = 'Magento\Sales\Model\Order\Shipment\Track';
    const PATH_MANAGER_INTERFACE      = '\Magento\Framework\Message\ManagerInterface';
    const PATH_ORDER_TRACK_COLLECTION = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection';
    const ERROR_ORDER_HAS_NO_SHIPMENT = 'No shipment can be made with this order. Shipments can not be created if the status is On Hold or if the product is digital.';

    /**
     * @var MyParcelCollection
     */
    public $myParcelCollection;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    public $request = null;

    /**
     * @var TrackSender
     */
    protected $trackSender;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var Order\Shipment\Track
     */
    protected $modelTrack;

    /**
     * @var \Magento\Framework\App\AreaList
     */
    protected $areaList;

    /**
     * @var \Magento\Framework\Message\ManagerInterface $messageManager
     */
    protected $messageManager;

    /**
     * @var \MyParcelNL\Magento\Helper\Data
     */
    protected $helper;

    protected $options = [
        'create_track_if_one_already_exist' => true,
        'request_type'                      => 'download',
        'package_type'                      => 'default',
        'carrier'                           => 'postnl',
        'positions'                         => null,
        'signature'                         => null,
        'only_recipient'                    => null,
        'return'                            => null,
        'large_format'                      => null,
        'age_check'                         => null,
        'insurance'                         => null,
        'label_amount'                      => NewShipment::DEFAULT_LABEL_AMOUNT,
        'digital_stamp_weight'              => null,
        'return_in_the_box'                 => false,
    ];

    /**
     * CreateAndPrintMyParcelTrack constructor.
     *
     * @param ObjectManagerInterface                  $objectManagerInterface
     * @param \Magento\Framework\App\RequestInterface $request
     * @param null                                    $areaList
     */
    public function __construct(ObjectManagerInterface $objectManagerInterface, $request = null, $areaList = null)
    {
        // @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
        if ($areaList) {
            $this->areaList = $areaList;
        }

        $this->objectManager = $objectManagerInterface;
        $this->request       = $request;
        $this->trackSender   = $this->objectManager->get('MyParcelNL\Magento\Model\Order\Email\Sender\TrackSender');

        $this->helper             = $objectManagerInterface->create(self::PATH_HELPER_DATA);
        $this->modelTrack         = $objectManagerInterface->create(self::PATH_ORDER_TRACK);
        $this->messageManager     = $objectManagerInterface->create(self::PATH_MANAGER_INTERFACE);
        $this->myParcelCollection = (new MyParcelCollection())->setUserAgents(['Magento2'=> $this->helper->getVersion()]);
    }

    /**
     * Set options from POST or GET variables
     *
     * @return $this
     */
    public function setOptionsFromParameters()
    {
        // If options isset
        foreach (array_keys($this->options) as $option) {
            if ($this->request->getParam('mypa_' . $option) === null) {
                if ($this->request->getParam('mypa_extra_options_checkboxes_in_form') === null) {
                    // Use default options
                    $this->options[$option] = null;
                } else {
                    // Checkbox isset but false
                    $this->options[$option] = false;
                }
            } else {
                $this->options[$option] = $this->request->getParam('mypa_' . $option);
            }
        }

        $label_amount = $this->request->getParam('mypa_label_amount') ?? NewShipment::DEFAULT_LABEL_AMOUNT;

        if ($label_amount) {
            $this->options['label_amount'] = $label_amount;
        }

        // Remove position if paper size == A6
        if ($this->request->getParam('mypa_paper_size', 'A6') != 'A4') {
            $this->options['positions'] = null;
        }

        if ($this->request->getParam('mypa_request_type') == null) {
            $this->options['request_type'] = 'download';
        }

        if ($this->request->getParam('mypa_request_type') != 'concept') {
            $this->options['create_track_if_one_already_exist'] = false;
        }

        $returnInTheBox = $this->helper->getGeneralConfig('print/return_in_the_box');
        if (ReturnInTheBox::NO_OPTIONS === $returnInTheBox || ReturnInTheBox::EQUAL_TO_SHIPMENT === $returnInTheBox) {
            $this->options['return_in_the_box'] = $returnInTheBox;
        }

        return $this;
    }

    /**
     * Get all options
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Get option by key
     *
     * @param $option
     *
     * @return mixed
     */
    public function getOption($option)
    {
        return $this->options[$option];
    }

    /**
     * Add MyParcel consignment to collection
     *
     * @param $consignment AbstractConsignment
     *
     * @return $this
     * @throws \Exception
     */
    public function addConsignment(AbstractConsignment $consignment)
    {
        $this->myParcelCollection->addConsignment($consignment);

        return $this;
    }

    public function apiKeyIsCorrect()
    {
        return $this->helper->apiKeyIsCorrect();
    }

    /**
     * Update sales_order table
     *
     * @param $orderId
     *
     * @return array
     */
    public function getHtmlForGridColumns($orderId)
    {
        /**
         * @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
         */
        // Temporarily fix to translate in cronjob
        if (! empty($this->areaList)) {
            $areaObject = $this->areaList->getArea(\Magento\Framework\App\Area::AREA_ADMINHTML);
            $areaObject->load(\Magento\Framework\App\Area::PART_TRANSLATE);
        }
        $tracks = $this->getTracksCollectionByOrderId($orderId);

        $data       = ['track_status' => [], 'track_number' => []];
        $columnHtml = ['track_status' => '', 'track_number' => ''];

        /**
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $track
         */
        foreach ($tracks as $track) {
            // Set all Track data in array
            if ($track['myparcel_status'] !== null) {
                $data['track_status'][] = __('status_' . $track['myparcel_status']);
            }
            if ($track['track_number']) {
                $data['track_number'][] = $track['track_number'];
            }
        }

        // Create html
        if ($data['track_status']) {
            $columnHtml['track_status'] = implode('<br>', $data['track_status']);
        }
        if ($data['track_number']) {
            $columnHtml['track_number'] = implode('<br>', $data['track_number']);
        }

        return $columnHtml;
    }

    /**
     * Check if track already exists
     *
     * @param \Magento\Sales\Model\ResourceModel\Order\Shipment $shipment
     *
     * @return bool
     */
    protected function shipmentHasTrack($shipment)
    {
        return $this->getTrackByShipment($shipment)->count() == 0 ? false : true;
    }

    /**
     * Create new Magento Track
     *
     * @param Order\Shipment $shipment
     *
     * @return \Magento\Sales\Model\Order\Shipment\Track
     * @throws \Exception
     */
    protected function setNewMagentoTrack($shipment)
    {
        /** @var \Magento\Sales\Model\Order\Shipment\Track $track */
        $track = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $track
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(TrackTraceHolder::MYPARCEL_CARRIER_CODE)
            ->setTitle(TrackTraceHolder::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber(TrackAndTrace::VALUE_EMPTY)
            ->save();

        return $track;
    }

    /**
     * Get all tracks
     *
     * @param \Magento\Sales\Model\ResourceModel\Order\Shipment $shipment
     *
     * @return \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection
     */
    protected function getTrackByShipment($shipment)
    {
        /* @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection $collection */
        $collection = $this->objectManager->create(self::PATH_ORDER_TRACK_COLLECTION);
        $collection
            ->addAttributeToFilter('parent_id', $shipment->getId());

        return $collection;
    }

    /**
     * Get MyParcel Track from Magento Track
     *
     * @param Order\Shipment\Track $magentoTrack
     *
     * @return TrackTraceHolder $myParcelTrack
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function createConsignmentAndGetTrackTraceHolder($magentoTrack): TrackTraceHolder
    {
        $trackTraceHolder = new TrackTraceHolder(
            $this->objectManager,
            $this->helper,
            $magentoTrack->getShipment()->getOrder()
        );
        $trackTraceHolder->convertDataFromMagentoToApi($magentoTrack, $this->options);

        return $trackTraceHolder;
    }

    /**
     * @param $orderId
     *
     * @return array
     */
    private function getTracksCollectionByOrderId($orderId)
    {
        /** @var \Magento\Framework\App\ResourceConnection $connection */
        $connection = $this->objectManager->create('\Magento\Framework\App\ResourceConnection');
        $conn       = $connection->getConnection();
        $select     = $conn->select()
                           ->from(
                               ['main_table' => $connection->getTableName('sales_shipment_track')]
                           )
                           ->where('main_table.order_id=?', $orderId);
        $tracks     = $conn->fetchAll($select);

        return $tracks;
    }
}
