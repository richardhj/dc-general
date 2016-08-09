<?php

/**
 * This file is part of contao-community-alliance/dc-general.
 *
 * (c) 2013-2015 Contao Community Alliance.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/dc-general
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Stefan Heimes <stefan_heimes@hotmail.com>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @author     Andreas Isaak <andy.jared@googlemail.com>
 * @author     David Greminger <david.greminger@1up.io>
 * @author     Oliver Hoff <oliver@hofff.com>
 * @author     Patrick Kahl <kahl.patrick@googlemail.com>
 * @author     Stefan Lindecke <github.com@chektrion.de>
 * @author     Andreas Nölke <zero@brothers-project.de>
 * @author     David Molineus <david.molineus@netzmacht.de>
 * @copyright  2013-2015 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\Controller;

use ContaoCommunityAlliance\DcGeneral\Action;
use ContaoCommunityAlliance\DcGeneral\Clipboard\Filter;
use ContaoCommunityAlliance\DcGeneral\Clipboard\FilterInterface;
use ContaoCommunityAlliance\DcGeneral\Clipboard\Item;
use ContaoCommunityAlliance\DcGeneral\Clipboard\ItemInterface;
use ContaoCommunityAlliance\DcGeneral\Contao\DataDefinition\Definition\Contao2BackendViewDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\ViewHelpers;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\Data\ModelIdInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\BasicDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\Properties\PropertyInterface;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\View\GroupAndSortingInformationInterface;
use ContaoCommunityAlliance\DcGeneral\Data\CollectionInterface;
use ContaoCommunityAlliance\DcGeneral\Data\DataProviderInterface;
use ContaoCommunityAlliance\DcGeneral\Data\DefaultCollection;
use ContaoCommunityAlliance\DcGeneral\Data\LanguageInformationInterface;
use ContaoCommunityAlliance\DcGeneral\Data\ModelInterface;
use ContaoCommunityAlliance\DcGeneral\Data\MultiLanguageDataProviderInterface;
use ContaoCommunityAlliance\DcGeneral\DcGeneralEvents;
use ContaoCommunityAlliance\DcGeneral\EnvironmentInterface;
use ContaoCommunityAlliance\DcGeneral\Event\ActionEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PostDuplicateModelEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PostPasteModelEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PreDuplicateModelEvent;
use ContaoCommunityAlliance\DcGeneral\Event\PrePasteModelEvent;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralInvalidArgumentException;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\Factory\DcGeneralFactory;

/**
 * This class serves as main controller class in dc general.
 *
 * It holds various methods for data manipulation and retrieval that is non view related.
 */
class DefaultController implements ControllerInterface
{
    /**
     * The attached environment.
     *
     * @var EnvironmentInterface
     */
    private $environment;

    /**
     * Error message.
     *
     * @var string
     */
    protected $notImplMsg =
        '<divstyle="text-align:center; font-weight:bold; padding:40px;">
        The function/view &quot;%s&quot; is not implemented.<br />Please
        <a
            target="_blank"
            style="text-decoration:underline"
            href="https://github.com/contao-community-alliance/dc-general/issues">support us</a>
        to add this important feature!</div>';

    /**
     * Throw an exception that an unknown method has been called.
     *
     * @param string $name      Method name.
     *
     * @param array  $arguments The method arguments.
     *
     * @return void
     *
     * @throws DcGeneralRuntimeException Always.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __call($name, $arguments)
    {
        throw new DcGeneralRuntimeException('Error Processing Request: ' . $name, 1);
    }

    /**
     * {@inheritDoc}
     */
    public function setEnvironment(EnvironmentInterface $environment)
    {
        $this->environment = $environment;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getEnvironment()
    {
        return $this->environment;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Action $action)
    {
        $event = new ActionEvent($this->getEnvironment(), $action);
        $this->getEnvironment()->getEventDispatcher()->dispatch(DcGeneralEvents::ACTION, $event);

        return $event->getResponse();
    }

    /**
     * {@inheritDoc}
     */
    public function searchParentOfIn(ModelInterface $model, CollectionInterface $models)
    {
        $environment   = $this->getEnvironment();
        $definition    = $environment->getDataDefinition();
        $relationships = $definition->getModelRelationshipDefinition();

        foreach ($models as $candidate) {
            /** @var ModelInterface $candidate */
            foreach ($relationships->getChildConditions($candidate->getProviderName()) as $condition) {
                if ($condition->matches($candidate, $model)) {
                    return $candidate;
                }

                $provider = $environment->getDataProvider($condition->getDestinationName());
                $config   = $provider
                    ->getEmptyConfig()
                    ->setFilter($condition->getFilter($candidate));

                $result = $this->searchParentOfIn($model, $provider->fetchAll($config));
                if ($result === true) {
                    return $candidate;
                } elseif ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @throws DcGeneralInvalidArgumentException When a root model has been passed or not in hierarchical mode.
     */
    public function searchParentOf(ModelInterface $model)
    {
        $environment   = $this->getEnvironment();
        $definition    = $environment->getDataDefinition();
        $relationships = $definition->getModelRelationshipDefinition();
        $mode          = $definition->getBasicDefinition()->getMode();

        if ($mode === BasicDefinitionInterface::MODE_HIERARCHICAL) {
            if ($this->isRootModel($model)) {
                throw new DcGeneralInvalidArgumentException('Invalid condition, root models can not have parents!');
            }
            // To speed up, some conditions have an inverse filter - we should use them!
            // Start from the root data provider and walk through the whole tree.
            $provider  = $environment->getDataProvider($definition->getBasicDefinition()->getRootDataProvider());
            $condition = $relationships->getRootCondition();
            $config    = $provider->getEmptyConfig()->setFilter($condition->getFilterArray());

            return $this->searchParentOfIn($model, $provider->fetchAll($config));
        } elseif ($mode === BasicDefinitionInterface::MODE_PARENTEDLIST) {
            $provider  = $environment->getDataProvider($definition->getBasicDefinition()->getParentDataProvider());
            $condition = $relationships->getChildCondition(
                $definition->getBasicDefinition()->getParentDataProvider(),
                $definition->getBasicDefinition()->getDataProvider()
            );
            $config    = $provider->getEmptyConfig();
            // This is pretty expensive, we fetch all models from the parent provider here.
            // This can be much faster by using the inverse condition if present.
            foreach ($provider->fetchAll($config) as $candidate) {
                if ($condition->matches($candidate, $model)) {
                    return $candidate;
                }
            }

            return null;
        }

        throw new DcGeneralInvalidArgumentException('Invalid condition, not in hierarchical mode!');
    }

    /**
     * {@inheritDoc}
     */
    public function assembleAllChildrenFrom($objModel, $strDataProvider = '')
    {
        if ($strDataProvider == '') {
            $strDataProvider = $objModel->getProviderName();
        }

        $arrIds = array();

        if ($strDataProvider == $objModel->getProviderName()) {
            $arrIds = array($objModel->getId());
        }

        // Check all data providers for children of the given element.
        $conditions = $this
            ->getEnvironment()
            ->getDataDefinition()
            ->getModelRelationshipDefinition()
            ->getChildConditions($objModel->getProviderName());
        foreach ($conditions as $objChildCondition) {
            $objDataProv = $this->getEnvironment()->getDataProvider($objChildCondition->getDestinationName());
            $objConfig   = $objDataProv->getEmptyConfig();
            $objConfig->setFilter($objChildCondition->getFilter($objModel));

            foreach ($objDataProv->fetchAll($objConfig) as $objChild) {
                /** @var ModelInterface $objChild */
                if ($strDataProvider == $objChild->getProviderName()) {
                    $arrIds[] = $objChild->getId();
                }

                $arrIds = array_merge($arrIds, $this->assembleAllChildrenFrom($objChild, $strDataProvider));
            }
        }

        return $arrIds;
    }

    /**
     * Retrieve all siblings of a given model.
     *
     * @param ModelInterface   $model           The model for which the siblings shall be retrieved from.
     *
     * @param string|null      $sortingProperty The property name to use for sorting.
     *
     * @param ModelIdInterface $parentId        The (optional) parent id to use.
     *
     * @return CollectionInterface
     *
     * @throws DcGeneralRuntimeException When no parent model can be located.
     */
    protected function assembleSiblingsFor(
        ModelInterface $model,
        $sortingProperty = null,
        ModelIdInterface $parentId = null
    ) {
        $environment   = $this->getEnvironment();
        $definition    = $environment->getDataDefinition();
        $provider      = $environment->getDataProvider($model->getProviderName());
        $config        = $environment->getBaseConfigRegistry()->getBaseConfig($parentId);
        $relationships = $definition->getModelRelationshipDefinition();

        // Root model in hierarchical mode?
        if ($this->isRootModel($model)) {
            $condition = $relationships->getRootCondition();

            if ($condition) {
                $config->setFilter($condition->getFilterArray());
            }
        } elseif ($definition->getBasicDefinition()->getMode() === BasicDefinitionInterface::MODE_HIERARCHICAL) {
            // Are we at least in hierarchical mode?
            $parent = $this->searchParentOf($model);

            if (!$parent instanceof ModelInterface) {
                throw new DcGeneralRuntimeException(
                    'Parent could not be found, are the parent child conditions correct?'
                );
            }

            $condition = $relationships->getChildCondition($parent->getProviderName(), $model->getProviderName());
            $config->setFilter($condition->getFilter($parent));
        }

        if ($sortingProperty) {
            $config->setSorting(array((string) $sortingProperty => 'ASC'));
        }

        // Handle grouping.
        /** @var Contao2BackendViewDefinitionInterface $viewDefinition */
        $viewDefinition = $definition->getDefinition(Contao2BackendViewDefinitionInterface::NAME);
        if ($viewDefinition && $viewDefinition instanceof Contao2BackendViewDefinitionInterface) {
            $listingConfig        = $viewDefinition->getListingConfig();
            $sortingProperties    = array_keys((array) $listingConfig->getDefaultSortingFields());
            $sortingPropertyIndex = array_search($sortingProperty, $sortingProperties);

            if ($sortingPropertyIndex !== false && $sortingPropertyIndex > 0) {
                $sortingProperties = array_slice($sortingProperties, 0, $sortingPropertyIndex);
                $filters           = $config->getFilter();

                foreach ($sortingProperties as $propertyName) {
                    $filters[] = array(
                        'operation' => '=',
                        'property'  => $propertyName,
                        'value'     => $model->getProperty($propertyName),
                    );
                }

                $config->setFilter($filters);
            }
        }

        $siblings = $provider->fetchAll($config);

        return $siblings;
    }

    /**
     * Retrieve children of a given model.
     *
     * @param ModelInterface $model           The model for which the children shall be retrieved.
     *
     * @param string|null    $sortingProperty The property name to use for sorting.
     *
     * @return CollectionInterface
     *
     * @throws DcGeneralRuntimeException When not in hierarchical mode.
     */
    protected function assembleChildrenFor(ModelInterface $model, $sortingProperty = null)
    {
        $environment   = $this->getEnvironment();
        $definition    = $environment->getDataDefinition();
        $provider      = $environment->getDataProvider($model->getProviderName());
        $config        = $environment->getBaseConfigRegistry()->getBaseConfig();
        $relationships = $definition->getModelRelationshipDefinition();

        if ($definition->getBasicDefinition()->getMode() !== BasicDefinitionInterface::MODE_HIERARCHICAL) {
            throw new DcGeneralRuntimeException('Unable to retrieve children in non hierarchical mode.');
        }

        $condition = $relationships->getChildCondition($model->getProviderName(), $model->getProviderName());
        $config->setFilter($condition->getFilter($model));

        if ($sortingProperty) {
            $config->setSorting(array((string) $sortingProperty => 'ASC'));
        }

        $siblings = $provider->fetchAll($config);

        return $siblings;
    }

    /**
     * {@inheritDoc}
     */
    public function updateModelFromPropertyBag($model, $propertyValues)
    {
        if (!$propertyValues) {
            return $this;
        }
        $environment = $this->getEnvironment();
        $input       = $environment->getInputProvider();

        foreach ($propertyValues as $property => $value) {
            try {
                $model->setProperty($property, $value);
            } catch (\Exception $exception) {
                $propertyValues->markPropertyValueAsInvalid($property, $exception->getMessage());
            }
        }

        $basicDefinition = $environment->getDataDefinition()->getBasicDefinition();

        if (($basicDefinition->getMode() & (
                    BasicDefinitionInterface::MODE_PARENTEDLIST
                    | BasicDefinitionInterface::MODE_HIERARCHICAL)
            )
            && ($input->hasParameter('pid'))
        ) {
            $parentModelId      = ModelId::fromSerialized($input->getParameter('pid'));
            $providerName       = $basicDefinition->getDataProvider();
            $parentProviderName = $parentModelId->getDataProviderName();
            $objParentModel     = $this->fetchModelFromProvider(
                $parentModelId->getId(),
                $parentModelId->getDataProviderName()
            );

            $relationship = $environment
                ->getDataDefinition()
                ->getModelRelationshipDefinition()
                ->getChildCondition($parentProviderName, $providerName);

            if ($relationship && $relationship->getSetters()) {
                $relationship->applyTo($objParentModel, $model);
            }
        }

        return $this;
    }

    /**
     * Return all supported languages from the default data data provider.
     *
     * @param mixed $mixID The id of the item for which to retrieve the valid languages.
     *
     * @return array
     */
    public function getSupportedLanguages($mixID)
    {
        $environment     = $this->getEnvironment();
        $objDataProvider = $environment->getDataProvider();

        // Check if current data provider supports multi language.
        if ($objDataProvider instanceof MultiLanguageDataProviderInterface) {
            $supportedLanguages = $objDataProvider->getLanguages($mixID);
        } else {
            $supportedLanguages = null;
        }

        // Check if we have some languages.
        if ($supportedLanguages == null) {
            return array();
        }

        // Make an array from the collection.
        $arrLanguage = array();
        $translator  = $environment->getTranslator();
        foreach ($supportedLanguages as $value) {
            /** @var LanguageInformationInterface $value */
            $arrLanguage[$value->getLocale()] = $translator->translate('LNG.' . $value->getLocale(), 'languages');
        }

        return $arrLanguage;
    }

    /**
     * Handle a property in a cloned model.
     *
     * @param ModelInterface        $model        The cloned model.
     *
     * @param PropertyInterface     $property     The property to handle.
     *
     * @param DataProviderInterface $dataProvider The data provider the model originates from.
     *
     * @return void
     */
    private function handleClonedModelProperty(
        ModelInterface $model,
        PropertyInterface $property,
        DataProviderInterface $dataProvider
    ) {
        $extra    = $property->getExtra();
        $propName = $property->getName();

        // Check doNotCopy.
        if (isset($extra['doNotCopy']) && $extra['doNotCopy'] === true) {
            $model->setProperty($propName, null);
            return;
        }

        // Check uniqueness.
        if (isset($extra['unique'])
            && $extra['unique'] === true
            && !$dataProvider->isUniqueValue($propName, $model->getProperty($propName))
        ) {
            // Implicit "do not copy" unique values, they cannot be unique anymore.
            $model->setProperty($propName, null);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws DcGeneralRuntimeException For constraint violations.
     */
    public function createClonedModel($model)
    {
        $clone = clone $model;
        $clone->setId(null);

        $environment  = $this->getEnvironment();
        $properties   = $environment->getDataDefinition()->getPropertiesDefinition();
        $dataProvider = $environment->getDataProvider($clone->getProviderName());

        foreach (array_keys($clone->getPropertiesAsArray()) as $propName) {
            // If the property is not known, remove it.
            if (!$properties->hasProperty($propName)) {
                continue;
            }

            $property = $properties->getProperty($propName);
            $this->handleClonedModelProperty($clone, $property, $dataProvider);
        }

        return $clone;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException When the model id is invalid.
     */
    public function fetchModelFromProvider($modelId, $providerName = null)
    {
        if ($providerName === null) {
            if (is_string($modelId)) {
                $modelId = ModelId::fromSerialized($modelId);
            }
        } else {
            $modelId = ModelId::fromValues($providerName, $modelId);
        }
        if (!($modelId instanceof ModelIdInterface)) {
            throw new \InvalidArgumentException('Invalid model id passed: ' . var_export($modelId, true));
        }

        $dataProvider = $this->getEnvironment()->getDataProvider($modelId->getDataProviderName());
        $item         = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($modelId->getId()));

        return $item;
    }

    /**
     * {@inheritDoc}
     */
    public function createEmptyModelWithDefaults()
    {
        $environment        = $this->getEnvironment();
        $definition         = $environment->getDataDefinition();
        $dataProvider       = $environment->getDataProvider();
        $propertyDefinition = $definition->getPropertiesDefinition();
        $properties         = $propertyDefinition->getProperties();
        $model              = $dataProvider->getEmptyModel();

        foreach ($properties as $property) {
            $propName = $property->getName();

            if ($property->getDefaultValue() !== null) {
                $model->setProperty($propName, $property->getDefaultValue());
            }
        }

        return $model;
    }

    /**
     * {@inheritDoc}
     */
    public function getModelFromClipboardItem(ItemInterface $item)
    {
        $modelId = $item->getModelId();

        if (!$modelId) {
            return null;
        }

        $environment  = $this->getEnvironment();
        $dataProvider = $environment->getDataProvider($modelId->getDataProviderName());
        $config       = $dataProvider->getEmptyConfig()->setId($modelId->getId());
        $model        = $dataProvider->fetch($config);

        return $model;
    }

    /**
     * {@inheritDoc}
     */
    public function getModelsFromClipboardItems(array $items)
    {
        $environment = $this->getEnvironment();
        $models      = new DefaultCollection();

        foreach ($items as $item) {
            /** @var ItemInterface $item */
            $modelId      = $item->getModelId();
            $dataProvider = $environment->getDataProvider($item->getDataProviderName());

            if ($modelId) {
                $model = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($modelId->getId()));

                // Make sure model exists.
                if ($model) {
                    $models->push($model);
                }
                continue;
            }

            $models->push($dataProvider->getEmptyModel());
        }

        return $models;
    }

    /**
     * {@inheritDoc}
     */
    public function getModelsFromClipboard(ModelIdInterface $parentModelId = null)
    {
        $environment       = $this->getEnvironment();
        $dataDefinition    = $environment->getDataDefinition();
        $basicDefinition   = $dataDefinition->getBasicDefinition();
        $modelProviderName = $basicDefinition->getDataProvider();
        $clipboard         = $environment->getClipboard();

        $filter = new Filter();
        $filter->andModelIsFromProvider($modelProviderName);
        if ($parentModelId) {
            $filter->andParentIsFromProvider($parentModelId->getDataProviderName());
        } else {
            $filter->andHasNoParent();
        }

        return $this->getModelsFromClipboardItems($clipboard->fetch($filter));
    }

    /**
     * {@inheritDoc}
     */
    public function applyClipboardActions(
        ModelIdInterface $source = null,
        ModelIdInterface $after = null,
        ModelIdInterface $into = null,
        ModelIdInterface $parentModelId = null,
        FilterInterface $filter = null,
        array &$items = array()
    ) {
        if ($source) {
            $actions = $this->getActionsFromSource($source, $parentModelId);
        } else {
            $actions = $this->fetchModelsFromClipboard($filter, $parentModelId);
        }

        return $this->doActions($actions, $after, $into, $parentModelId, $items);
    }

    /**
     * Fetch actions from source.
     *
     * @param ModelIdInterface      $source        The source id.
     * @param ModelIdInterface|null $parentModelId The parent id.
     *
     * @return array
     */
    private function getActionsFromSource(ModelIdInterface $source, ModelIdInterface $parentModelId = null)
    {
        $environment  = $this->getEnvironment();
        $dataProvider = $environment->getDataProvider($source->getDataProviderName());

        $filterConfig = $dataProvider->getEmptyConfig();
        $filterConfig->setId($source->getId());

        $model = $dataProvider->fetch($filterConfig);

        $actions = array(
            array(
                'model' => $model,
                'item'  => new Item(ItemInterface::CUT, $parentModelId, ModelId::fromModel($model)),
            )
        );

        return $actions;
    }

    /**
     * Fetch actions from the clipboard.
     *
     * @param FilterInterface|null $filter        The clipboard filter.
     * @param ModelIdInterface     $parentModelId The parent id.
     *
     * @return array
     */
    private function fetchModelsFromClipboard(FilterInterface $filter = null, ModelIdInterface $parentModelId = null)
    {
        $environment    = $this->getEnvironment();
        $dataDefinition = $environment->getDataDefinition();

        if (!$filter) {
            $filter = new Filter();
        }

        $basicDefinition   = $dataDefinition->getBasicDefinition();
        $modelProviderName = $basicDefinition->getDataProvider();
        $filter->andModelIsFromProvider($modelProviderName);
        if ($parentModelId) {
            $filter->andParentIsFromProvider($parentModelId->getDataProviderName());
        } else {
            $filter->andHasNoParent();
        }

        $environment = $this->getEnvironment();
        $clipboard   = $environment->getClipboard();
        $items       = $clipboard->fetch($filter);
        $actions     = array();

        foreach ($items as $item) {
            $model = null;

            if (!$item->isCreate() && $item->getModelId()) {
                $modelId      = $item->getModelId();
                $dataProvider = $environment->getDataProvider($item->getDataProviderName());
                $config       = $dataProvider->getEmptyConfig()->setId($modelId->getId());
                $model        = $dataProvider->fetch($config);
            }

            $actions[] = array(
                'model' => $model,
                'item'  => $item,
            );
        }

        return $actions;
    }

    /**
     * Effectively do the actions.
     *
     * @param array            $actions       The actions collection.
     * @param ModelIdInterface $after         The previous model id.
     * @param ModelIdInterface $into          The hierarchical parent model id.
     * @param ModelIdInterface $parentModelId The parent model id.
     * @param array            $items         Write-back clipboard items.
     *
     * @return CollectionInterface
     */
    private function doActions(
        array $actions,
        ModelIdInterface $after = null,
        ModelIdInterface $into = null,
        ModelIdInterface $parentModelId = null,
        array &$items = array()
    ) {
        $environment = $this->getEnvironment();

        if ($parentModelId) {
            $dataProvider = $environment->getDataProvider($parentModelId->getDataProviderName());
            $config       = $dataProvider->getEmptyConfig()->setId($parentModelId->getId());
            $parentModel  = $dataProvider->fetch($config);
        } else {
            $parentModel = null;
        }

        // Holds models, that need deep-copy
        $deepCopyList = array();

        // Apply create and copy actions
        foreach ($actions as &$action) {
            $this->applyAction($action, $deepCopyList, $parentModel);
        }

        // When pasting after another model, apply same grouping information
        $this->ensureSameGrouping($actions, $after);

        // Now apply sorting and persist all models
        $models = $this->sortAndPersistModels($actions, $after, $into, $parentModelId, $items);

        // At least, go ahead with the deep copy
        $this->doDeepCopy($deepCopyList);

        return $models;
    }

    /**
     * Apply the action onto the model.
     *
     * This will create or clone the model in the action.
     *
     * @param array          $action       The action, containing a model and an item.
     * @param array          $deepCopyList A list of models that need deep copy.
     * @param ModelInterface $parentModel  The parent model.
     *
     * @return void
     *
     * @throws \UnexpectedValueException When the action is neither create, copy or deep copy.
     */
    private function applyAction(array &$action, array &$deepCopyList, ModelInterface $parentModel = null)
    {
        $environment = $this->getEnvironment();

        /** @var ModelInterface|null $model */
        $model = $action['model'];
        /** @var ItemInterface $item */
        $item = $action['item'];

        if ($item->isCreate()) {
            // create new model
            $model = $this->createEmptyModelWithDefaults();
        } elseif ($item->isCopy() || $item->isDeepCopy()) {
            // copy model
            $modelId      = ModelId::fromModel($model);
            $dataProvider = $environment->getDataProvider($modelId->getDataProviderName());
            $config       = $dataProvider->getEmptyConfig()->setId($modelId->getId());
            $model        = $dataProvider->fetch($config);

            $clonedModel = $this->doCloneAction($model);

            if ($item->isDeepCopy()) {
                $deepCopyList[] = array(
                    'origin' => $model,
                    'model'  => $clonedModel,
                );
            }

            $model = $clonedModel;
        }

        if (!$model) {
            throw new \UnexpectedValueException(
                'Invalid clipboard action entry, no model created. ' . $item->getAction()
            );
        }

        if ($parentModel) {
            $this->setParent($model, $parentModel);
        }

        $action['model'] = $model;
    }

    /**
     * Effectively do the clone action on the model.
     *
     * @param ModelInterface $model The model to clone.
     *
     * @return ModelInterface Return the cloned model.
     */
    private function doCloneAction(ModelInterface $model)
    {
        $environment = $this->getEnvironment();

        // Make a duplicate.
        $clonedModel = $this->createClonedModel($model);

        // Trigger the pre duplicate event.
        $duplicateEvent = new PreDuplicateModelEvent($environment, $clonedModel, $model);
        $environment->getEventDispatcher()->dispatch($duplicateEvent::NAME, $duplicateEvent);

        // And trigger the post event for it.
        $duplicateEvent = new PostDuplicateModelEvent($environment, $clonedModel, $model);
        $environment->getEventDispatcher()->dispatch($duplicateEvent::NAME, $duplicateEvent);

        return $clonedModel;
    }

    /**
     * Ensure all models have the same grouping.
     *
     * @param array            $actions The actions collection.
     * @param ModelIdInterface $after   The previous model id.
     *
     * @return void
     */
    private function ensureSameGrouping(array $actions, ModelIdInterface $after = null)
    {
        $environment  = $this->getEnvironment();
        $groupingMode = ViewHelpers::getGroupingMode($environment);
        if ($groupingMode && $after && $after->getId()) {
            // when pasting after another item, inherit the grouping field
            $groupingField = $groupingMode['property'];
            $dataProvider  = $environment->getDataProvider($after->getDataProviderName());
            $previous      = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($after->getId()));
            $groupingValue = $previous->getProperty($groupingField);

            foreach ($actions as $action) {
                /** @var ModelInterface $model */
                $model = $action['model'];
                $model->setProperty($groupingField, $groupingValue);
            }
        }
    }

    /**
     * Apply sorting and persist all models.
     *
     * @param array            $actions       The actions collection.
     * @param ModelIdInterface $after         The previous model id.
     * @param ModelIdInterface $into          The hierarchical parent model id.
     * @param ModelIdInterface $parentModelId The parent model id.
     * @param array            $items         Write-back clipboard items.
     *
     * @return DefaultCollection|ModelInterface[]
     *
     * @throws DcGeneralRuntimeException When the parameters for the pasting destination are invalid.
     */
    private function sortAndPersistModels(
        array $actions,
        ModelIdInterface $after = null,
        ModelIdInterface $into = null,
        ModelIdInterface $parentModelId = null,
        array &$items = array()
    ) {
        $environment    = $this->getEnvironment();
        $dataDefinition = $environment->getDataDefinition();
        $manualSorting  = ViewHelpers::getManualSortingProperty($environment);

        /** @var DefaultCollection|ModelInterface[] $models */
        $models = new DefaultCollection();
        foreach ($actions as $action) {
            $models->push($action['model']);
            $items[] = $action['item'];
        }

        // Trigger for each model the pre persist event.
        foreach ($models as $model) {
            $event = new PrePasteModelEvent($environment, $model);
            $environment->getEventDispatcher()->dispatch($event::NAME, $event);
        }

        if ($after && $after->getId()) {
            $dataProvider = $environment->getDataProvider($after->getDataProviderName());
            $previous     = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($after->getId()));
            $this->pasteAfter($previous, $models, $manualSorting);
        } elseif ($into && $into->getId()) {
            $dataProvider = $environment->getDataProvider($into->getDataProviderName());
            $parent       = $dataProvider->fetch($dataProvider->getEmptyConfig()->setId($into->getId()));
            $this->pasteInto($parent, $models, $manualSorting);
        } elseif (($after && $after->getId() == '0') || ($into && $into->getId() == '0')) {
            if ($dataDefinition->getBasicDefinition()->getMode() === BasicDefinitionInterface::MODE_HIERARCHICAL) {
                foreach ($models as $model) {
                    // Paste top means root in hierarchical mode!
                    $this->setRootModel($model);
                }
            }
            $this->pasteTop($models, $manualSorting, $parentModelId);
        } elseif ($parentModelId) {
            if ($manualSorting) {
                $this->pasteTop($models, $manualSorting, $parentModelId);
            } else {
                $dataProvider = $environment->getDataProvider();
                $dataProvider->saveEach($models);
            }
        } else {
            throw new DcGeneralRuntimeException('Invalid parameters.');
        }

        // Trigger for each model the past persist event.
        foreach ($models as $model) {
            $event = new PostPasteModelEvent($environment, $model);
            $environment->getEventDispatcher()->dispatch($event::NAME, $event);
        }

        return $models;
    }

    /**
     * Do deep copy.
     *
     * @param array $deepCopyList The deep copy list.
     *
     * @return void
     */
    protected function doDeepCopy(array $deepCopyList)
    {
        if (empty($deepCopyList)) {
            return;
        }

        $factory                     = DcGeneralFactory::deriveFromEnvironment($this->getEnvironment());
        $dataDefinition              = $this->getEnvironment()->getDataDefinition();
        $modelRelationshipDefinition = $dataDefinition->getModelRelationshipDefinition();
        $childConditions             = $modelRelationshipDefinition->getChildConditions($dataDefinition->getName());

        foreach ($deepCopyList as $deepCopy) {
            /** @var ModelInterface $origin */
            $origin = $deepCopy['origin'];
            /** @var ModelInterface $model */
            $model = $deepCopy['model'];

            $parentId = ModelId::fromModel($model);

            foreach ($childConditions as $childCondition) {
                // create new destination environment
                $destinationName = $childCondition->getDestinationName();
                $factory->setContainerName($destinationName);
                $destinationEnvironment    = $factory->createEnvironment();
                $destinationDataDefinition = $destinationEnvironment->getDataDefinition();
                $destinationViewDefinition = $destinationDataDefinition->getDefinition(
                    Contao2BackendViewDefinitionInterface::NAME
                );
                $destinationDataProvider   = $destinationEnvironment->getDataProvider();
                $destinationController     = $destinationEnvironment->getController();
                /** @var Contao2BackendViewDefinitionInterface $destinationViewDefinition */
                /** @var DefaultController $destinationController */
                $listingConfig             = $destinationViewDefinition->getListingConfig();
                $groupAndSortingCollection = $listingConfig->getGroupAndSortingDefinition();
                $groupAndSorting           = $groupAndSortingCollection->getDefault();

                // ***** fetch the children
                $filter = $childCondition->getFilter($origin);

                // apply parent-child condition
                $config = $destinationDataProvider->getEmptyConfig();
                $config->setFilter($filter);

                // apply sorting
                $sorting = array();
                foreach ($groupAndSorting as $information) {
                    /** @var GroupAndSortingInformationInterface $information */
                    $sorting[$information->getProperty()] = $information->getSortingMode();
                }
                $config->setSorting($sorting);

                // receive children
                $children = $destinationDataProvider->fetchAll($config);

                // ***** do the deep copy
                $actions = array();

                // build the copy actions
                foreach ($children as $childModel) {
                    $childModelId = ModelId::fromModel($childModel);

                    $actions[] = array(
                        'model' => $childModel,
                        'item'  => new Item(
                            ItemInterface::DEEP_COPY,
                            $parentId,
                            $childModelId
                        )
                    );
                }

                // do the deep copy
                $childrenModels = $destinationController->doActions($actions, null, null, $parentId);

                // ensure parent-child condition
                foreach ($childrenModels as $childrenModel) {
                    $childCondition->applyTo($model, $childrenModel);
                }
                $destinationDataProvider->saveEach($childrenModels);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function pasteTop(CollectionInterface $models, $sortedBy, ModelIdInterface $parentId = null)
    {
        $environment = $this->getEnvironment();

        // Enforce proper sorting now.
        $siblings    = $this->assembleSiblingsFor($models->get(0), $sortedBy, $parentId);
        $sortManager = new SortingManager($models, $siblings, $sortedBy, null);
        $newList     = $sortManager->getResults();

        $environment->getDataProvider($models->get(0)->getProviderName())->saveEach($newList);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \RuntimeException When no models have been passed.
     */
    public function pasteAfter(ModelInterface $previousModel, CollectionInterface $models, $sortedBy)
    {
        if ($models->length() == 0) {
            throw new \RuntimeException('No models passed to pasteAfter().');
        }
        $environment = $this->getEnvironment();

        if (in_array(
            $environment
                ->getDataDefinition()
                ->getBasicDefinition()
                ->getMode(),
            array(
                BasicDefinitionInterface::MODE_HIERARCHICAL,
                BasicDefinitionInterface::MODE_PARENTEDLIST
            )
        )) {
            $parentModel = null;
            $parentModel = null;

            if (!$this->isRootModel($previousModel)) {
                $parentModel = $this->searchParentOf($previousModel);
            }

            foreach ($models as $model) {
                /** @var ModelInterface $model */
                $this->setSameParent($model, $previousModel, $parentModel ? $parentModel->getProviderName() : null);
            }
        }

        // Enforce proper sorting now.
        $siblings    = $this->assembleSiblingsFor($previousModel, $sortedBy);
        $sortManager = new SortingManager($models, $siblings, $sortedBy, $previousModel);
        $newList     = $sortManager->getResults();

        $environment->getDataProvider($previousModel->getProviderName())->saveEach($newList);
    }

    /**
     * {@inheritDoc}
     */
    public function pasteInto(ModelInterface $parentModel, CollectionInterface $models, $sortedBy)
    {
        $environment = $this->getEnvironment();

        foreach ($models as $model) {
            $this->setParent($model, $parentModel);
        }

        // Enforce proper sorting now.
        $siblings    = $this->assembleChildrenFor($parentModel, $sortedBy);
        $sortManager = new SortingManager($models, $siblings, $sortedBy);
        $newList     = $sortManager->getResults();

        $environment->getDataProvider($newList->get(0)->getProviderName())->saveEach($newList);
    }

    /**
     * {@inheritDoc}
     */
    public function isRootModel(ModelInterface $model)
    {
        if ($this
                ->getEnvironment()
                ->getDataDefinition()
                ->getBasicDefinition()
                ->getMode() !== BasicDefinitionInterface::MODE_HIERARCHICAL
        ) {
            return false;
        }

        return $this
            ->getEnvironment()
            ->getDataDefinition()
            ->getModelRelationshipDefinition()
            ->getRootCondition()
            ->matches($model);
    }

    /**
     * {@inheritDoc}
     */
    public function setRootModel(ModelInterface $model)
    {
        $rootCondition = $this
            ->getEnvironment()
            ->getDataDefinition()
            ->getModelRelationshipDefinition()
            ->getRootCondition();

        $rootCondition->applyTo($model);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setParent(ModelInterface $childModel, ModelInterface $parentModel)
    {
        $this
            ->getEnvironment()
            ->getDataDefinition()
            ->getModelRelationshipDefinition()
            ->getChildCondition($parentModel->getProviderName(), $childModel->getProviderName())
            ->applyTo($parentModel, $childModel);
    }

    /**
     * {@inheritDoc}
     */
    public function setSameParent(ModelInterface $receivingModel, ModelInterface $sourceModel, $parentTable)
    {
        if ($this->isRootModel($sourceModel)) {
            $this->setRootModel($receivingModel);
        } else {
            $this
                ->getEnvironment()
                ->getDataDefinition()
                ->getModelRelationshipDefinition()
                ->getChildCondition($parentTable, $receivingModel->getProviderName())
                ->copyFrom($sourceModel, $receivingModel);
        }
    }
}
