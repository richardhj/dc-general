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

use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\ContaoWidgetManager;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\Data\ModelIdInterface;
use ContaoCommunityAlliance\DcGeneral\Data\ModelInterface;
use ContaoCommunityAlliance\DcGeneral\Data\PropertyValueBagInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\Properties\PropertyInterface;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;

/**
 * The class handle the "editAll" commands.
 */
class EditAllHandler extends AbstractOverrideEditAllHandler
{
    /**
     * Handle the action.
     *
     * @return void
     */
    public function process()
    {
        $action = $this->getEvent()->getAction();
        if ($action->getName() !== 'editAll') {
            return;
        }

        $environment    = $this->getEnvironment();
        $inputProvider  = $environment->getInputProvider();
        $dataDefinition = $environment->getDataDefinition();
        $translator     = $environment->getTranslator();

        $renderInformation = new \ArrayObject();

        $this->invisibleUnusedProperties();

        $this->buildFieldSets($renderInformation);

        $this->updateErrorInformation($renderInformation);

        if (!$renderInformation->offsetGet('error')) {
            $this->handleSubmit();
        }

        $this->getEvent()->setResponse(
            $this->renderTemplate(
                array(
                    'subHeadline' =>
                        $translator->translate('MSC.' . $inputProvider->getParameter('mode') . 'Selected') . ': ' .
                        $translator->translate('MSC.all.0'),
                    'fieldsets'   => $renderInformation->offsetGet('fieldsets'),
                    'table'       => $dataDefinition->getName(),
                    'error'       => $renderInformation->offsetGet('error'),
                    'breadcrumb'  => $this->renderBreadcrumb(),
                    'editButtons' => $this->getEditButtons(),
                    'noReload'    => (bool) $renderInformation->offsetGet('error')
                )
            )
        );
    }

    /**
     * Build the field sets for each model.
     *
     * Return error if their given.
     *
     * @param \ArrayObject $renderInformation The render information.
     *
     * @return void
     */
    private function buildFieldSets(\ArrayObject $renderInformation)
    {
        $environment   = $this->getEnvironment();
        $inputProvider = $environment->getInputProvider();
        $formInputs    = $inputProvider->getValue('FORM_INPUTS');

        $collection = $this->getCollectionFromSession();

        $fieldSets = array();
        $errors    = array();

        while ($collection->count() > 0) {
            $model   = $collection->shift();
            $modelId = ModelId::fromModel($model);

            $widgetManager = new ContaoWidgetManager($environment, $model);

            $editPropertyValuesBag = $this->getPropertyValueBagFromModel($model);
            if ($formInputs) {
                $this->handleEditCollection($editPropertyValuesBag, $model, $renderInformation);
            }

            $fields = $this->renderEditFields(
                $widgetManager,
                $model,
                $editPropertyValuesBag
            );

            if (count($fields) < 1) {
                continue;
            }

            $fieldSet = array(
                'label'   => $modelId->getSerialized(),
                'model'   => $model,
                'legend'  => str_replace('::', '____', $modelId->getSerialized()),
                'class'   => 'tl_box',
                'palette' => implode('', $fields)
            );

            $fieldSets[] = $fieldSet;
        }

        $renderInformation->offsetSet('fieldsets', $this->handleLegendCollapsed($fieldSets));
        $renderInformation->offsetSet('error', $errors);
    }

    /**
     * Handle legend how are open if errors available.
     *
     * @param array $fieldSets The field sets.
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function handleLegendCollapsed(array $fieldSets)
    {
        $editInformation = $GLOBALS['container']['dc-general.edit-information'];
        if (!$editInformation->hasAnyModelError()) {
            return $fieldSets;
        }

        foreach (array_keys($fieldSets) as $index) {
            if ($editInformation->getModelError($fieldSets[$index]['model'])) {
                continue;
            }

            $fieldSets[$index]['class'] .= ' collapsed';
        }

        return $fieldSets;
    }

    /**
     * Render the edit fields.
     *
     * @param ContaoWidgetManager       $widgetManager     The widget manager.
     *
     * @param ModelInterface            $model             The model.
     *
     * @param PropertyValueBagInterface $propertyValuesBag The property values.
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     *
     * @throws DcGeneralRuntimeException For unknown properties.
     * @throws DcGeneralInvalidArgumentException If the property is not contained within the bag.
     */
    private function renderEditFields(
        ContaoWidgetManager $widgetManager,
        ModelInterface $model,
        PropertyValueBagInterface $propertyValuesBag = null
    ) {
        $environment     = $this->getEnvironment();
        $dataDefinition  = $environment->getDataDefinition();
        $properties      = $dataDefinition->getPropertiesDefinition();
        $editInformation = $GLOBALS['container']['dc-general.edit-information'];
        $sessionValues   = $this->getEditPropertiesByModelId(ModelId::fromModel($model));

        $selectProperties = (array) $this->getPropertiesFromSession();

        $modelId      = ModelId::fromModel($model);
        $dataProvider = $environment->getDataProvider($modelId->getDataProviderName());

        $editModel = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($modelId->getId()));

        $fields = array();
        foreach ($selectProperties as $selectProperty) {
            if (!$this->ensurePropertyVisibleInModel($selectProperty->getName(), $editModel)) {
                $fields[] = $this->injectSelectParentPropertyInformation($selectProperty, $editModel, $propertyValuesBag);

                continue;
            }

            $editProperty = $this->buildEditProperty($selectProperty, $modelId);

            $properties->addProperty($editProperty);

            if ($propertyValuesBag
                && $propertyValuesBag->hasPropertyValue($selectProperty->getName())
            ) {
                $propertyValuesBag->setPropertyValue(
                    $selectProperty->getName(),
                    $editModel->getProperty($selectProperty->getName())
                );
            }

            $editErrors = $propertyValuesBag->getInvalidPropertyErrors();
            if ($editErrors
                && array_key_exists($selectProperty->getName(), $editErrors)
            ) {
                $propertyValuesBag->markPropertyValueAsInvalid(
                    $editProperty->getName(),
                    $editErrors[$selectProperty->getName()]
                );
            }

            $modelError = $editInformation->getModelError($editModel);
            if ($modelError && isset($modelError[$selectProperty->getName()])) {
                $propertyValuesBag->setPropertyValue(
                    $editProperty->getName(),
                    $sessionValues[$selectProperty->getName()]
                );

                $propertyValuesBag->setPropertyValue(
                    $selectProperty->getName(),
                    $sessionValues[$selectProperty->getName()]
                );

                $propertyValuesBag->markPropertyValueAsInvalid(
                    $editProperty->getName(),
                    $modelError[$selectProperty->getName()]
                );
            }

            $fields[] = $widgetManager->renderWidget($editProperty->getName(), false, $propertyValuesBag);

            $fields[] = $this->injectSelectSubPropertiesInformation($selectProperty, $editModel, $propertyValuesBag);
        }

        return $fields;
    }

    /**
     * Handle edit collection of models.
     *
     * @param PropertyValueBagInterface $editPropertyValuesBag The property values.
     *
     * @param ModelInterface            $model                 The model.
     *
     * @param \ArrayObject              $renderInformation     The render information.
     *
     * @return void
     */
    private function handleEditCollection(
        PropertyValueBagInterface $editPropertyValuesBag,
        ModelInterface $model,
        \ArrayObject $renderInformation
    ) {
        $environment = $this->getEnvironment();

        $dataProvider     = $environment->getDataProvider($model->getProviderName());
        $editCollection   = $dataProvider->getEmptyCollection();
        $revertCollection = $dataProvider->getEmptyCollection();

        $editCollection->push($model);

        $revertModel = clone $model;
        $revertModel->setId($model->getId());
        $revertCollection->push($model);

        $this->editCollection($editCollection, $editPropertyValuesBag, $renderInformation);

        $this->revertValuesByErrors($revertCollection);
    }

    /**
     * Build edit property from the original property.
     *
     * @param PropertyInterface $originalProperty The original property.
     *
     * @param ModelIdInterface  $modelId          The model id.
     *
     * @return PropertyInterface
     */
    private function buildEditProperty(PropertyInterface $originalProperty, ModelIdInterface $modelId)
    {
        $editPropertyClass = get_class($originalProperty);

        $editPropertyName = str_replace('::', '____', $modelId->getSerialized()) . '_' . $originalProperty->getName();

        $editProperty = new $editPropertyClass($editPropertyName);
        $editProperty->setLabel($originalProperty->getLabel());
        $editProperty->setDescription($originalProperty->getDescription());
        $editProperty->setDefaultValue($editProperty->getDefaultValue());
        $editProperty->setExcluded($originalProperty->isExcluded());
        $editProperty->setSearchable($originalProperty->isSearchable());
        $editProperty->setFilterable($originalProperty->isFilterable());
        $editProperty->setWidgetType($originalProperty->getWidgetType());
        $editProperty->setOptions($originalProperty->getOptions());
        $editProperty->setExplanation($originalProperty->getExplanation());
        $editProperty->setExtra($originalProperty->getExtra());

        return $editProperty;
    }
}
