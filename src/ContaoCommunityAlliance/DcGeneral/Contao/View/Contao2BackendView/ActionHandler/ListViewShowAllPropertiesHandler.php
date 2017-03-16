<?php

/**
 * This file is part of contao-community-alliance/dc-general.
 *
 * (c) 2013-2017 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general
 * @author     Sven Baumann <baumann.sv@gmail.com>
 * @copyright  2013-2017 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\ActionHandler;

use Contao\Message;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\ContaoBackendViewTemplate;
use ContaoCommunityAlliance\DcGeneral\Data\CollectionInterface;
use ContaoCommunityAlliance\DcGeneral\Data\DataProviderInterface;
use ContaoCommunityAlliance\DcGeneral\Data\ModelInterface;
use ContaoCommunityAlliance\DcGeneral\Data\NoOpDataProvider;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\Properties\DefaultProperty;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\Properties\PropertyInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\View\DefaultModelFormatterConfig;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;

/**
 * This class handles the rendering of list view "showAllProperties" actions.
 */
class ListViewShowAllPropertiesHandler extends AbstractListShowAllHandler
{
    /**
     * The template messages.
     *
     * @var array
     */
    protected $messages = array();

    /**
     * {@inheritdoc}
     */
    public function process()
    {
        $action = $this->getEvent()->getAction();
        if (('showAll' !== $action->getName())
            || ('properties' !== $action->getArguments()['select'])
        ) {
            return;
        }

        $environment     = $this->getEnvironment();
        $dataDefinition  = $environment->getDataDefinition();
        $basicDefinition = $dataDefinition->getBasicDefinition();

        $basicDefinition->setMode(BasicDefinitionInterface::MODE_FLAT);

        parent::process();

        $this->getEvent()->stopPropagation();
    }

    /**
     * Load the collection of fields.
     *
     * @return CollectionInterface
     * @throws DcGeneralRuntimeException When no source has been defined.
     */
    protected function loadCollection()
    {
        $dataProvider = $this->getPropertyDataProvider();

        return $this->getCollection($dataProvider);
    }

    /**
     * Return the property data provider.
     *
     * @return NoOpDataProvider
     *
     * @throws DcGeneralRuntimeException When no source has been defined.
     */
    protected function getPropertyDataProvider()
    {
        $environment    = $this->getEnvironment();
        $dataDefinition = $environment->getDataDefinition();

        $providerName = 'property.' . $dataDefinition->getName();

        $dataProvider = new NoOpDataProvider();
        $dataProvider->setBaseConfig(array('name' => $providerName));

        $this->setPropertyLabelFormatter($providerName);

        return $dataProvider;
    }

    /**
     * Set the label formatter for property data provider to the listing configuration.
     *
     * @param string $providerName The provider name.
     *
     * @return void
     */
    protected function setPropertyLabelFormatter($providerName)
    {
        $environment    = $this->getEnvironment();
        $dataDefinition = $environment->getDataDefinition();
        $properties     = $dataDefinition->getPropertiesDefinition();

        $listingConfig = $this->getViewSection()->getListingConfig();

        $labelFormatter = new DefaultModelFormatterConfig();
        $labelFormatter->setPropertyNames(array('name', 'description'));
        $labelFormatter->setFormat('%s <span style="color:#b3b3b3; padding-left:3px">[%s]</span>');
        $listingConfig->setLabelFormatter($providerName, $labelFormatter);

        // If property name not exits create dummy property for it.
        foreach (array('name', 'description') as $dummyName) {
            if (!$properties->hasProperty($dummyName)) {
                $dummyProperty = new DefaultProperty($dummyName);
                $dummyProperty->setWidgetType('dummyProperty');

                $properties->addProperty($dummyProperty);
            }
        }
    }

    /**
     * Return the field collection for each properties.
     *
     * @param DataProviderInterface $dataProvider The field data provider.
     *
     * @return CollectionInterface
     */
    protected function getCollection(DataProviderInterface $dataProvider)
    {
        $environment    = $this->getEnvironment();
        $dataDefinition = $environment->getDataDefinition();
        $properties     = $dataDefinition->getPropertiesDefinition();

        $collection = $dataProvider->getEmptyCollection();

        foreach ($properties as $property) {
            if (!$property->getWidgetType()
                || $property->getWidgetType() === 'dummyProperty'
            ) {
                continue;
            }

            if (!$this->isPropertyAllowed($property)) {
                continue;
            }

            $model = $dataProvider->getEmptyModel();
            $model->setID($property->getName());
            $model->setProperty(
                'name',
                $property->getLabel() ? $property->getLabel() : $property->getName()
            );
            $model->setProperty(
                'description',
                $property->getDescription() ? $property->getDescription() : $property->getName()
            );

            $this->handlePropertyFileTree($property, $model);
            $this->handlePropertyFileTreeOrder($property, $model);

            $collection->offsetSet($collection->count(), $model);
        }

        return $collection;
    }

    /**
     * Is property allowed for edit multiple.
     *
     * @param PropertyInterface $property The property.
     *
     * @return bool
     */
    private function isPropertyAllowed(PropertyInterface $property)
    {
        $environment   = $this->getEnvironment();
        $inputProvider = $environment->getInputProvider();

        $extra = (array) $property->getExtra();
        if (('override' === $inputProvider->getParameter('mode')
             && (true === $extra['doNotEditMultiple']))
            || (('override' === $inputProvider->getParameter('mode'))
                && ((true === $extra['unique'])
                    || (true === $extra['doNotOverrideMultiple'])))
        ) {
            // FIXME: Translate info message.
            Message::addInfo(
                sprintf(
                    'The property "%s" isn´t allow to use at %s.',
                    $inputProvider->getParameter('mode'),
                    $property->getLabel() ? $property->getLabel() : $property->getName()
                )
            );

            return false;
        }

        return true;
    }

    /**
     * Handle property file tree.
     *
     * @param PropertyInterface $property The property.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function handlePropertyFileTree(PropertyInterface $property)
    {
        if ('fileTree' !== $property->getWidgetType()) {
            return;
        }

        $extra = $property->getExtra();
        if (true === empty($extra['orderField'])) {
            return;
        }

        $script = '<script type="text/javascript">
                        $("%s").addEvent("change", function(ev) {
                            Backend.toggleCheckboxes(ev.target, "%s");
                            GeneralLogger.info("The file order property is checked " + $("%s").checked)
                        });
                    </script>';

        $GLOBALS['TL_MOOTOOLS'][] =
            sprintf(
                $script,
                'properties_' . $property->getName(),
                'properties_' . $extra['orderField'],
                'properties_' . $extra['orderField']
            );
    }

    /**
     * Handle property file tree order.
     *
     * @param PropertyInterface $property The property.
     *
     * @param ModelInterface    $model    The model.
     *
     * @return void
     */
    private function handlePropertyFileTreeOrder(PropertyInterface $property, ModelInterface $model)
    {
        if ('fileTreeOrder' !== $property->getWidgetType()) {
            return;
        }

        $model->setMeta($model::CSS_ROW_CLASS, 'invisible');
    }

    /**
     * Prepare the template.
     *
     * @param ContaoBackendViewTemplate $template The template to populate.
     *
     * @return void
     */
    protected function renderTemplate(ContaoBackendViewTemplate $template)
    {
        $environment   = $this->getEnvironment();
        $inputProvider = $environment->getInputProvider();

        $this->getViewSection()->getListingConfig()->setShowColumns(false);

        parent::renderTemplate($template);

        // TODO translate sub headline.
        $template->set(
            'subHeadline',
            $this->translate('MSC.' . $inputProvider->getParameter('mode') . 'Selected') . ': Eigenschaften auswählen'
        );
        $template->set('mode', 'none');
        $template->set('floatRightSelectButtons', true);
        $template->set('selectCheckBoxName', 'properties[]');
        $template->set('selectCheckBoxIdPrefix', 'properties_');

        if ((null !== $template->get('action'))
            && (false !== strpos($template->get('action'), 'select=properties'))
        ) {
            $template->set('action', str_replace('select=properties', 'select=edit', $template->get('action')));
        }

        if (count($this->messages) > 0) {
            foreach (array_keys($this->messages) as $messageType) {
                $template->set($messageType, $this->messages[$messageType]);
            }
        }
    }


    /**
     * Retrieve a list of html buttons to use in the bottom panel (submit area) when in select mode.
     *
     * @return string[]
     */
    protected function getSelectButtons()
    {
        $environment   = $this->getEnvironment();
        $inputProvider = $environment->getInputProvider();

        $continueName = '';
        foreach (array('override', 'edit') as $subAction) {
            if (!$inputProvider->hasValue($subAction)) {
                continue;
            }

            $continueName = $subAction;
        }

        $confirmMessage = htmlentities(
            sprintf(
                '<h2 class="tl_error">%s</h2>' .
                '<p></p>' .
                '<div class="tl_submit_container">' .
                '<input class="%s" value="%s" onclick="%s">' .
                '</div>',
                specialchars($this->translate('MSC.nothingSelect')),
                'tl_submit',
                specialchars($this->translate('MSC.close')),
                'BackendGeneral.hideMessage(); return false;'
            )
        );
        $onClick        = 'BackendGeneral.confirmSelectOverrideEditAll(this, \'properties[]\', \'' .
                          $confirmMessage . '\'); return false;';

        $input = '<input type="submit" name="%s" id="%s" class="tl_submit" accesskey="%s" value="%s" onclick="%s">';

        $buttons['continue'] = sprintf(
            $input,
            $continueName,
            $continueName,
            'c',
            specialchars($this->translate('MSC.continue')),
            $onClick
        );

        return $buttons;
    }

    /**
     * Check if the action should be handled.
     *
     * @param string $mode The list mode.
     *
     * @return mixed
     */
    protected function wantToHandle($mode)
    {
        $action = $this->getEvent()->getAction();

        $arguments = $action->getArguments();

        return 'properties' === $arguments['select'];
    }

    /**
     * Determine the template to use.
     *
     * @param array $groupingInformation The grouping information as retrieved via ViewHelpers::getGroupingMode().
     *
     * @return ContaoBackendViewTemplate
     */
    protected function determineTemplate($groupingInformation)
    {
        return $this->getTemplate('dcbe_general_listView');
    }
}
