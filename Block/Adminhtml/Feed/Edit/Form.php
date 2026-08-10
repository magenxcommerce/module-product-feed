<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Block\Adminhtml\Feed\Edit;

use Magenx\ProductFeed\Controller\Adminhtml\Feed as FeedController;
use Magenx\ProductFeed\Model\Config\Source\Delimiter;
use Magenx\ProductFeed\Model\Config\Source\Enclosure;
use Magenx\ProductFeed\Model\Config\Source\Format;
use Magenx\ProductFeed\Model\Config\Source\Marketplace;
use Magenx\ProductFeed\Model\Feed;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Backend\Block\Widget\Form\Renderer\Fieldset;
use Magento\Config\Model\Config\Source\Yesno;
use Magento\Rule\Block\Conditions as ConditionsRenderer;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;
use Magento\Store\Model\System\Store as SystemStore;

/**
 * The feed edit form.
 *
 * A legacy Widget form rather than a ui_component one, because the product
 * filter is a condition tree and the ui_component stack has no rule fieldset.
 *
 * See addFilterFieldset() for the two renderers involved and why only one of
 * them may go through createBlock().
 */
class Form extends Generic
{
    /**
     * JS form object name for the condition tree. Must match what
     * Controller\Adminhtml\Feed\NewConditionHtml receives as its `form` param -
     * a mismatch is silent and breaks only the "+" button.
     */
    private const CONDITIONS_FIELDSET_ID = 'rule_conditions_fieldset';

    private const FORM_NAME = 'edit_form';

    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly SystemStore $systemStore,
        private readonly Format $formatSource,
        private readonly Delimiter $delimiterSource,
        private readonly Enclosure $enclosureSource,
        private readonly Marketplace $marketplaceSource,
        private readonly Yesno $yesNo,
        private readonly ConditionsRenderer $conditionsRenderer,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    protected function _prepareForm(): self
    {
        $feed = $this->getFeed();

        $form = $this->_formFactory->create([
            'data' => [
                'id' => 'edit_form',
                'action' => $this->getUrl('*/*/save', ['feed_id' => $feed?->getFeedId()]),
                'method' => 'post',
            ],
        ]);
        $form->setHtmlIdPrefix('feed_');

        $this->addGeneralFieldset($form, $feed);
        $this->addContentFieldset($form, $feed);
        $this->addFilterFieldset($form, $feed);
        $this->addScheduleFieldset($form, $feed);
        $this->addValidationFieldset($form, $feed);

        if ($feed !== null) {
            $form->setValues($feed->getData());
        }

        $form->setUseContainer(true);
        $this->setForm($form);

        return parent::_prepareForm();
    }

    private function addGeneralFieldset(\Magento\Framework\Data\Form $form, ?Feed $feed): void
    {
        $fieldset = $form->addFieldset('base_fieldset', ['legend' => __('Feed Information')]);

        if ($feed !== null && $feed->getFeedId() !== null) {
            $fieldset->addField('feed_id', 'hidden', ['name' => 'feed_id']);
        }

        $fieldset->addField('name', 'text', [
            'name' => 'name',
            'label' => __('Name'),
            'title' => __('Name'),
            'required' => true,
        ]);

        $fieldset->addField('code', 'text', [
            'name' => 'code',
            'label' => __('Code'),
            'note' => __(
                'Stable identifier used by the CLI. Derived from the name when left empty. '
                . 'Changing it after scripts reference it will break them.'
            ),
        ]);

        $fieldset->addField('store_id', 'select', [
            'name' => 'store_id',
            'label' => __('Store View'),
            'title' => __('Store View'),
            'required' => true,
            'values' => $this->systemStore->getStoreValuesForForm(false, false),
            'note' => __('Prices, URLs and attribute values are all exported in this store view\'s scope.'),
        ]);

        $fieldset->addField('format', 'select', [
            'name' => 'format',
            'label' => __('Format'),
            'required' => true,
            'values' => $this->formatSource->toOptionArray(),
            'note' => __(
                'Only the field-mapped formats can be pushed to a catalog API such as Meta\'s; '
                . 'a free-form XML template can only be published as a file.'
            ),
        ]);

        $fieldset->addField('marketplace', 'select', [
            'name' => 'marketplace',
            'label' => __('Target Channel'),
            'values' => $this->marketplaceSource->toOptionArray(),
            'note' => __('Advisory only - it labels the feed and does not restrict where it can be delivered.'),
        ]);

        $fieldset->addField('filename', 'text', [
            'name' => 'filename',
            'label' => __('File Name'),
            'required' => true,
            'note' => __(
                'Template expressions are allowed, e.g. products-{{ context.date }}.xml'
            ),
        ]);

        $fieldset->addField('is_active', 'select', [
            'name' => 'is_active',
            'label' => __('Active'),
            'values' => $this->yesNo->toOptionArray(),
            'note' => __('Only active feeds are generated on a schedule.'),
        ]);

        $fieldset->addField('description', 'textarea', [
            'name' => 'description',
            'label' => __('Description'),
            'style' => 'height: 5em;',
        ]);

        if ($feed !== null && $feed->getFeedId() !== null) {
            $fieldset->addField('status_note', 'note', [
                'label' => __('Status'),
                'text' => $this->describeStatus($feed),
            ]);
        }
    }

    private function addContentFieldset(\Magento\Framework\Data\Form $form, ?Feed $feed): void
    {
        $fieldset = $form->addFieldset('content_fieldset', ['legend' => __('Content')]);

        $fieldset->addField('template', 'textarea', [
            'name' => 'template',
            'label' => __('Template'),
            'style' => 'height: 28em; font-family: monospace;',
            'note' => __(
                'The whole document. Wrap the per-product part in '
                . '{% for product in context.products %} ... {% endfor %} - everything before it is written '
                . 'once at the start and everything after it once at the end, which is what lets a large '
                . 'catalog be exported in slices. Leave empty to use Field Mapping instead.'
            ),
        ]);

        $fieldset->addField('field_map', 'textarea', [
            'name' => 'field_map',
            'label' => __('Field Mapping (JSON)'),
            'style' => 'height: 14em; font-family: monospace;',
            'note' => __(
                'For CSV / TSV / JSONL feeds: a JSON array of {"column": "...", "value": "..."}, '
                . 'where value is a template expression. This is also what a catalog-API push sends.'
            ),
        ]);

        $fieldset->addField('csv_delimiter', 'select', [
            'name' => 'csv_delimiter',
            'label' => __('CSV Delimiter'),
            'values' => $this->delimiterSource->toOptionArray(),
        ]);

        $fieldset->addField('csv_enclosure', 'select', [
            'name' => 'csv_enclosure',
            'label' => __('CSV Enclosure'),
            'values' => $this->enclosureSource->toOptionArray(),
            'note' => __('With None, the delimiter is stripped out of every value instead.'),
        ]);

        $fieldset->addField('csv_include_header', 'select', [
            'name' => 'csv_include_header',
            'label' => __('Include Header Row'),
            'values' => $this->yesNo->toOptionArray(),
        ]);

        $fieldset->addField('csv_bom', 'select', [
            'name' => 'csv_bom',
            'label' => __('UTF-8 BOM'),
            'values' => $this->yesNo->toOptionArray(),
            'note' => __('A few consumers require it; most reject it. Leave off unless told otherwise.'),
        ]);
    }

    /**
     * The product filter's condition tree.
     *
     * TWO RENDERERS, AND THEY ARE NOT INTERCHANGEABLE:
     *
     *  - Magento\Backend\Block\Widget\Form\Renderer\Fieldset IS a block, so it is
     *    built with getLayout()->createBlock(). It also carries per-fieldset STATE
     *    (setFieldSetId, setNewChildUrl), so a form with two trees needs one
     *    instance each - this form has one, so one is correct here.
     *
     *  - Magento\Rule\Block\Conditions is NOT a block despite the namespace. It
     *    implements only Data\Form\Element\Renderer\RendererInterface, so passing
     *    it to createBlock() throws "does not implement BlockInterface" and takes
     *    the whole page down. It MUST be injected, which is exactly what
     *    CatalogRule and SalesRule do.
     *
     * setConditionFormName() below is what makes the "+" button work. Without it
     * the tree renders and saves perfectly, and adding a condition silently does
     * nothing - the JS has no form object to post back to.
     */
    private function addFilterFieldset(\Magento\Framework\Data\Form $form, ?Feed $feed): void
    {
        $renderer = $this->getLayout()->createBlock(Fieldset::class);
        $renderer->setTemplate('Magento_CatalogRule::promo/fieldset.phtml');
        $renderer->setNewChildUrl(
            $this->getUrl('magenx_product_feed/feed/newConditionHtml/form/' . self::CONDITIONS_FIELDSET_ID)
        );
        $renderer->setFieldSetId(self::CONDITIONS_FIELDSET_ID);

        $fieldset = $form->addFieldset('conditions_fieldset', [
            'legend' => __('Products to Include'),
        ]);
        $fieldset->setRenderer($renderer);

        $fieldset->addField('conditions', 'text', [
            'name' => 'conditions',
            'label' => __('Conditions'),
            'title' => __('Conditions'),
            'data-form-part' => self::FORM_NAME,
        ])->setRule($feed)->setRenderer($this->conditionsRenderer);

        if ($feed !== null) {
            $this->setConditionFormName($feed->getConditions());
        }
    }

    /**
     * Bind every condition in the tree - including nested ones - to this form's
     * JS object, so the "+" and "-" controls post back to the right place.
     */
    private function setConditionFormName(AbstractCondition $condition): void
    {
        $condition->setFormName(self::FORM_NAME);
        $condition->setJsFormObject(self::CONDITIONS_FIELDSET_ID);

        $children = $condition->getConditions();
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child instanceof AbstractCondition) {
                    $this->setConditionFormName($child);
                }
            }
        }
    }

    private function addScheduleFieldset(\Magento\Framework\Data\Form $form, ?Feed $feed): void
    {
        $fieldset = $form->addFieldset('schedule_fieldset', ['legend' => __('Schedule')]);

        $fieldset->addField('schedule_times', 'text', [
            'name' => 'schedule_times',
            'label' => __('Times of Day'),
            'note' => __(
                'Comma-separated HH:MM, in this store view\'s timezone. A feed whose slot passes while cron '
                . 'is down runs as soon as cron recovers, within six hours.'
            ),
        ]);

        $fieldset->addField('schedule_days', 'text', [
            'name' => 'schedule_days',
            'label' => __('Days of the Week'),
            'note' => __('Comma-separated, 1 = Monday .. 7 = Sunday. Leave empty for every day.'),
        ]);
    }

    private function addValidationFieldset(\Magento\Framework\Data\Form $form, ?Feed $feed): void
    {
        $fieldset = $form->addFieldset('validation_fieldset', ['legend' => __('Validation')]);

        $fieldset->addField('validation_rules', 'textarea', [
            'name' => 'validation_rules',
            'label' => __('Rules (JSON)'),
            'style' => 'height: 14em; font-family: monospace;',
            'note' => __(
                'A JSON array of {"field", "type", "severity", "message"} plus any rule parameters. '
                . 'Types: required, max_length, min_length, start_with, end_with, is_one_of, alphanumeric, '
                . 'ascii, unicode, numeric, without_html.'
            ),
        ]);
    }

    private function describeStatus(Feed $feed): string
    {
        $parts = [
            (string) __('Status: %1', (string) $feed->getData('status')),
            (string) __('Products: %1', (string) ($feed->getData('product_count') ?? '-')),
            (string) __('Last generated: %1', (string) ($feed->getData('last_generated_at') ?? __('never'))),
        ];

        $error = trim((string) $feed->getData('last_error'));
        if ($error !== '') {
            $parts[] = (string) __('Last error: %1', $error);
        }

        return implode('<br/>', array_map('htmlspecialchars', $parts));
    }

    private function getFeed(): ?Feed
    {
        $feed = $this->_coreRegistry->registry(FeedController::REGISTRY_KEY);

        return $feed instanceof Feed ? $feed : null;
    }
}
