<?php

namespace AppBundle\Util;

use Biz\Taxonomy\Service\CategoryService;
use Topxia\Service\Common\ServiceKernel;

class CategoryBuilder
{
    protected $categories = array();

    protected $indent = '　';

    public function buildForTaxonomy($groupCode)
    {
        $group = $this->getCategoryService()->getGroupByCode($groupCode);
        if (empty($group)) {
            return;
        }

        $this->categories = $this->getCategoryService()->getCategoryTree($group['id']);
    }

    public function buildForQuestion($bankId)
    {
        $this->categories = $this->getQuestionCategoryService()->getCategoryTree($bankId);
    }

    public function build($categories)
    {
        $this->categories = $categories;
    }

    public function convertToChoices()
    {
        $choices = array();

        foreach ($this->categories as $category) {
            $choices[$category['id']] = str_repeat(is_null($this->indent) ? '' : $this->indent, ($category['depth'] - 1)).$category['name'];
        }

        return $choices;
    }

    public function setIndent($indent)
    {
        $this->indent = $indent;
    }

    /**
     * @return CategoryService
     */
    private function getCategoryService()
    {
        return $this->createService('Taxonomy:CategoryService');
    }

    /**
     * @return \Biz\Question\Service\CategoryService
     */
    private function getQuestionCategoryService()
    {
        return $this->createService('Question:CategoryService');
    }

    protected function createService($alias)
    {
        return self::getServiceKernel()->getBiz()->service($alias);
    }

    protected static function getServiceKernel()
    {
        return ServiceKernel::instance();
    }
}
