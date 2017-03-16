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
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\EncodePropertyValueFromWidgetEvent;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\Data\ModelInterface;
use ContaoCommunityAlliance\DcGeneral\Data\PropertyValueBag;
use ContaoCommunityAlliance\DcGeneral\Data\PropertyValueBagInterface;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;

/**
 * The class handle the "overrideAll" commands.
 */
class OverrideAllHandler extends AbstractOverrideEditAllHandler
{
    /**
     * Create the override all mask.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     *
     * @throws DcGeneralInvalidArgumentException When create property value bug, the construct argument isn´t right.
     * @throws DcGeneralRuntimeException When the model id can´t parse.
     */
    public function process()
    {
        $action = $this->getEvent()->getAction();
        if ($action->getName() !== 'overrideAll') {
            return;
        }

        $environment     = $this->getEnvironment();
        $inputProvider   = $environment->getInputProvider();
        $dataDefinition  = $environment->getDataDefinition();
        $translator      = $environment->getTranslator();
        $editInformation = $GLOBALS['container']['dc-general.edit-information'];


        $renderInformation = new \ArrayObject();
        $properties        = $this->getOverrideProperties();


        $propertyValueBag = new PropertyValueBag();
        foreach ($properties as $property) {
            $propertyValueBag->setPropertyValue($property->getName(), $property->getDefaultValue());
        }

        if (false !== $inputProvider->hasValue('FORM_INPUTS')) {
            foreach ($inputProvider->getValue('FORM_INPUTS') as $formInput) {
                $propertyValueBag->setPropertyValue($formInput, $inputProvider->getValue($formInput));
            }
        }

        $this->invisibleUnusedProperties();

        $this->handleOverrideCollection($renderInformation, $propertyValueBag);

        $this->renderFieldSets($renderInformation, $propertyValueBag);

        $this->updateErrorInformation($renderInformation);

        if (!$editInformation->hasAnyModelError()) {
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
                    'noReload'    => (bool) $editInformation->hasAnyModelError()
                )
            )
        );
    }

    /**
     * Handle invalid property value bag.
     *
     * @param PropertyValueBagInterface|null $propertyValueBag The property value bag.
     *
     * @param ModelInterface|null            $model            The model.
     *
     * @return void
     */
    protected function handleInvalidPropertyValueBag(
        PropertyValueBagInterface $propertyValueBag = null,
        ModelInterface $model = null
    ) {
        if ((null === $propertyValueBag)
            || (null === $model)
        ) {
            return;
        }

        $environment   = $this->getEnvironment();
        $inputProvider = $environment->getInputProvider();

        foreach (array_keys($propertyValueBag->getArrayCopy()) as $propertyName) {
            $allErrors    = $propertyValueBag->getPropertyValueErrors($propertyName);
            $mergedErrors = array();
            if (count($allErrors) > 0) {
                foreach ($allErrors as $error) {
                    if (in_array($error, $mergedErrors)) {
                        continue;
                    }

                    $mergedErrors[] = $error;
                }
            }

            $eventPropertyValueBag = new PropertyValueBag();
            $eventPropertyValueBag->setPropertyValue($propertyName, $inputProvider->getValue($propertyName, true));

            $event = new EncodePropertyValueFromWidgetEvent($environment, $model, $eventPropertyValueBag);
            $event->setProperty($propertyName)
                ->setValue($inputProvider->getValue($propertyName, true));
            $environment->getEventDispatcher()->dispatch(EncodePropertyValueFromWidgetEvent::NAME, $event);

            $propertyValueBag->setPropertyValue($propertyName, $event->getValue());

            if (count($mergedErrors) > 0) {
                $propertyValueBag->markPropertyValueAsInvalid($propertyName, $mergedErrors);
            }
        }
    }

    /**
     * Handle override of model collection.
     *
     * @param \ArrayObject              $renderInformation The render information.
     *
     * @param PropertyValueBagInterface $propertyValues    The property values.
     *
     * @return void
     */
    private function handleOverrideCollection(
        \ArrayObject $renderInformation,
        PropertyValueBagInterface $propertyValues = null
    ) {
        if (!$propertyValues) {
            return;
        }

        $revertCollection = $this->getCollectionFromSession();

        $this->editCollection($this->getCollectionFromSession(), $propertyValues, $renderInformation);

        if ($propertyValues->hasNoInvalidPropertyValues()) {
            $this->handleSubmit();
        }

        $this->revertValuesByErrors($revertCollection);
    }

    /**
     * Return the select properties from the session.
     *
     * @return array
     */
    private function getOverrideProperties()
    {
        $selectProperties = $this->getPropertiesFromSession();

        $properties = array();
        foreach (array_keys($selectProperties) as $propertyName) {
            $properties[$propertyName] = $selectProperties[$propertyName];
        }

        return $properties;
    }

    /**
     * Render the field sets.
     *
     * @param \ArrayObject                   $renderInformation The render information.
     *
     * @param PropertyValueBagInterface|null $propertyValues    The property values.
     *
     * @return void
     */
    private function renderFieldSets(\ArrayObject $renderInformation, PropertyValueBagInterface $propertyValues = null)
    {
        $environment = $this->getEnvironment();

        $properties = $this->getOverrideProperties();

        $model = $this->getEmptyModel();

        $widgetManager = new ContaoWidgetManager($environment, $model);

        $errors   = array();
        $fieldSet = array('palette' => '', 'class' => 'tl_box');

        $propertyNames = $propertyValues ? array_keys($propertyValues->getArrayCopy()) : array_keys($properties);

        foreach ($propertyNames as $propertyName) {
            $errors = $this->getPropertyValueErrors($propertyValues, $propertyName, $errors);

            if (false === array_key_exists($propertyName, $properties)) {
                continue;
            }

            $property = $properties[$propertyName];

            $this->setDefaultValue($propertyValues, $propertyName);

            $widgetManager->getWidget($property->getName(), $propertyValues);

            if (!$this->ensurePropertyVisibleInModel($property->getName(), $model)) {
                $fieldSet['palette'] .=
                    $this->injectSelectParentPropertyInformation($property, $model, $propertyValues);

                continue;
            }

            if ($extra = $property->getExtra()) {
                foreach (array('tl_class') as $extraName) {
                    unset($extra[$extraName]);
                }

                $property->setExtra($extra);
            }

            $fieldSet['palette'] .= $widgetManager->renderWidget($property->getName(), false, $propertyValues);

            $fieldSet['palette'] .= $this->injectSelectSubPropertiesInformation($property, $model, $propertyValues);
        }

        $renderInformation->offsetSet('fieldsets', array($fieldSet));
        $renderInformation->offsetSet('error', $errors);
    }

    /**
     * Get the empty Model.
     *
     * @return ModelInterface
     */
    private function getEmptyModel()
    {
        $environment          = $this->getEnvironment();
        $inputProvider        = $environment->getInputProvider();
        $dataDefinition       = $environment->getDataDefinition();
        $modelRelation        = $dataDefinition->getModelRelationshipDefinition();
        $parentDefinition     = $environment->getParentDataDefinition();
        $propertiesDefinition = $dataDefinition->getPropertiesDefinition();
        $dataProvider         = $environment->getDataProvider($dataDefinition->getName());

        $model = $dataProvider->getEmptyModel();
        $model->setId(0);
        if ($parentDefinition && $inputProvider->hasParameter('pid')) {
            $childCondition =
                $modelRelation->getChildCondition($parentDefinition->getName(), $dataDefinition->getName());

            $parentModelId = ModelId::fromSerialized($inputProvider->getParameter('pid'));

            foreach ($childCondition->getFilterArray() as $filter) {
                if (!$propertiesDefinition->hasProperty($filter['local'])) {
                    continue;
                }

                if ($propertiesDefinition->hasProperty($filter['local'])) {
                    $model->setProperty($filter['local'], $parentModelId->getId());
                }

                break;
            }
        }

        return $model;
    }

    /**
     * Get the merged property value errors.
     *
     * @param PropertyValueBagInterface $propertyValueBag The property value bag.
     *
     * @param string                    $propertyName     The property name.
     *
     * @param array                     $errors           The errors.
     *
     * @return array
     */
    private function getPropertyValueErrors(PropertyValueBagInterface $propertyValueBag, $propertyName, array $errors)
    {
        if (null !== $propertyValueBag
            && $propertyValueBag->hasPropertyValue($propertyName)
            && $propertyValueBag->isPropertyValueInvalid($propertyName)
        ) {
            $errors = array_merge(
                $errors,
                $propertyValueBag->getPropertyValueErrors($propertyName)
            );
        }

        return $errors;
    }

    /**
     * Set the default value if no value is set.
     *
     * @param PropertyValueBagInterface $propertyValueBag The property value bag.
     *
     * @param string                    $propertyName     The property name.
     *
     * @return void
     */
    private function setDefaultValue(PropertyValueBagInterface $propertyValueBag, $propertyName)
    {
        $environment          = $this->getEnvironment();
        $inputProvider        = $environment->getInputProvider();
        $dataDefinition       = $environment->getDataDefinition();
        $propertiesDefinition = $dataDefinition->getPropertiesDefinition();

        if (!$inputProvider->hasValue($propertyName)
            && $propertiesDefinition->hasProperty($propertyName)
        ) {
            $propertyValueBag->setPropertyValue(
                $propertyName,
                $propertiesDefinition->getProperty($propertyName)->getDefaultValue()
            );
        }
    }
}
