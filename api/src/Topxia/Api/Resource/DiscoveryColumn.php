<?php

namespace Topxia\Api\Resource;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Topxia\Common\ArrayToolkit;

class DiscoveryColumn extends BaseResource
{

    public function get(Application $app, Request $request)
    {
    	$result = $this->getDiscoveryColumnService()->getAllDiscoveryColumns();
    	if (empty($result)) {
    		return $this->error('error', '暂无分类内容!'); 
    	}

    	return $result;
    }

	public function filter(&$res)
    {
        
    }

    protected function getMobileShowService()
    {
    	return $this->getServiceKernel()->createService('DiscoveryColumn.DiscoveryColumnService');
    }
}