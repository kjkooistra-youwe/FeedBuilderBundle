<?php
/**
 * Created by PhpStorm.
 * User: jorisros
 * Date: 01/01/2018
 * Time: 22:52
 */

namespace FeedBuilderBundle\Service;

use FeedBuilderBundle\Event\FeedBuilderEvent;
use OutputDataConfigToolkitBundle\Service;
use Pimcore\Config;
use Pimcore\Model\DataObject\Concrete;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class FeedBuilderService
{
    private $dispatcher = null;
    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    const LOCATION_FILE = 'feedbuilder.php';

    /**
     * Returns the config file for the feedbuilder, see the feedbuilder.example.php
     *
     * @return Config\Config
     * @throws \Exception
     */
    public static function getConfig()
    {
        $systemConfigFile = Config::locateConfigFile(self::LOCATION_FILE);

        if(!file_exists($systemConfigFile)){
            throw new \Exception("Config file not found");
        }

        return new Config\Config(include($systemConfigFile));
    }

    /**
     * Returns the profile by ID or name
     *
     * @param $id
     * @throws \Exception
     */
    public static function getConfigOfProfile($id) {
        $config = self::getConfig();

        if(is_integer($id)){
            return $config->get('feeds')[$id];
        }

        if(is_string($id)){
            foreach ($config->get('feeds') as $feed) {
                if($feed->get('title') === $id) {
                    return $feed;
                }
            }
        }

        return null;
    }

    /**
     * Run the feedbuilder
     *
     * @param Config\Config $config
     */
    public function run(Config\Config $config) {

        $event = new FeedBuilderEvent();
        $event->setConfig($config);

        $config = $this->dispatcher->dispatch(FeedBuilderEvent::BEFORE_RUN, $event)->getConfig();

        $class = $config->get('class');
        $listing = $class.'\Listing';

        $criteria = new $listing();
        $criteria->setUnpublished(!$config->get('published'));
        $event->setListing($criteria);

        $criteria = $this->dispatcher->dispatch(FeedBuilderEvent::AFTER_SELECTION, $event)->getListing();
        $objects = $criteria->load();

        $result = [];
        /** @var Concrete $object */
        foreach ($objects as $object){
            $event->setObject($object);
            $object = $this->dispatcher->dispatch(FeedBuilderEvent::BEFORE_ROW, $event)->getObject();

            $specificationOutputChannel = Service::getOutputDataConfig($object,$config->get('channel'));

            $arrProperties = [];
            foreach($specificationOutputChannel as $property) {
                $arrProperties[$property->getLabeledValue($object)->label] = $property->getLabeledValue($object)->value;
            }

            $event->setArray($arrProperties);
            $arr = $this->dispatcher->dispatch(FeedBuilderEvent::AFTER_ROW, $event)->getArray();
            $result[$config->get('root')][] = $arr;
        }
        $event->setResult($result);

        $result = $this->dispatcher->dispatch(FeedBuilderEvent::AFTER_RUN, $event)->getResult();

        return $result;
    }
}
