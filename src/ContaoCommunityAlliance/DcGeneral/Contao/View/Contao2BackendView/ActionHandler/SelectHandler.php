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
 * @author     David Molineus <david.molineus@netzmacht.de>
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Stefan Heimes <stefan_heimes@hotmail.com>
 * @author     Sven Baumann <baumann.sv@gmail.com>
 * @copyright  2013-2017 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\ActionHandler;

use ContaoCommunityAlliance\Contao\Bindings\ContaoEvents;
use ContaoCommunityAlliance\Contao\Bindings\Events\System\GetReferrerEvent;
use ContaoCommunityAlliance\DcGeneral\Action;
use ContaoCommunityAlliance\DcGeneral\Clipboard\Filter;
use ContaoCommunityAlliance\DcGeneral\Contao\DataDefinition\Definition\Contao2BackendViewDefinitionInterface;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\Event\PrepareMultipleModelsActionEvent;
use ContaoCommunityAlliance\DcGeneral\Contao\View\Contao2BackendView\ViewHelpers;
use ContaoCommunityAlliance\DcGeneral\Data\ModelId;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\View\BackCommand;
use ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\View\Command;
use ContaoCommunityAlliance\DcGeneral\Exception\DcGeneralRuntimeException;
use ContaoCommunityAlliance\DcGeneral\View\ActionHandler\AbstractHandler;

/**
 * Class SelectController.
 *
 * This class handles multiple actions.
 */
class SelectHandler extends AbstractHandler
{
    /**
     * Handle the action.
     *
     * @return void
     *
     * @throws DcGeneralRuntimeException When the id is unparsable.
     */
    public function process()
    {
        if (!$this->getSelectAction()
            || $this->getEvent()->getAction()->getName() !== 'select'
        ) {
            return;
        }

        $submitAction = $this->getSubmitAction(true);

        $this->removeGlobalCommands();

        $this->handleSessionBySelectAction();

        // FIXME: What can i do for remove this loop.
        if ('models' === $this->getSelectAction()) {
            $this->handleBySelectActionModels();

            return;
        }

        $this->handleNonEditAction();
        $this->clearClipboardBySubmitAction();

        // FIXME: What can i do for remove this loop.
        if ('properties' === $this->getSelectAction()) {
            $this->handleBySelectActionProperties();

            return;
        }

        $this->handleNonSelectByShowAllAction();

        $this->handleGlobalCommands();

        $this->callAction($submitAction . 'All', array('mode' => $submitAction));
    }

    /**
     * Get the submit action name.
     *
     * @param boolean $regardSelectMode Regard the select mode parameter.
     *
     * @return string
     */
    private function getSubmitAction($regardSelectMode = false)
    {
        $inputProvider = $this->getEnvironment()->getInputProvider();
        $actions       = array('delete', 'cut', 'copy', 'override', 'edit');

        foreach ($actions as $action) {
            if ($inputProvider->hasValue($action)
                || $inputProvider->hasValue($action . '_save')
                || $inputProvider->hasValue($action . '_saveNback')
            ) {
                $inputProvider->setParameter('mode', $action);

                return $action;
            }
        }


        if ($regardSelectMode) {
            return $inputProvider->getParameter('mode') ?: null;
        }

        return null;
    }

    /**
     * Get the select action.
     *
     * @return string
     */
    private function getSelectAction()
    {
        return $this->getEnvironment()->getInputProvider()->getParameter('select');
    }

    /**
     * Handle by select action models.
     *
     * @return void
     */
    private function handleBySelectActionModels()
    {
        if ('models' !== $this->getSelectAction()) {
            return;
        }

        $this->clearClipboard();

        $this->handleGlobalCommands();

        $arguments           = $this->getEvent()->getAction()->getArguments();
        $arguments['mode']   = $this->getSubmitAction(true);
        $arguments['select'] = $this->getSelectAction();

        $this->callAction('showAll', $arguments);
    }

    /**
     * Handle by select action properties.
     *
     * @return void
     */
    private function handleBySelectActionProperties()
    {
        if ('properties' !== $this->getSelectAction()) {
            return;
        }

        $this->handleGlobalCommands();

        $arguments           = $this->getEvent()->getAction()->getArguments();
        $arguments['mode']   = $this->getSubmitAction(true);
        $arguments['select'] = $this->getSelectAction();

        $this->callAction('showAll', $arguments);
    }

    /**
     * Handle the session by select action.
     *
     * @return void
     */
    private function handleSessionBySelectAction()
    {
        $environment   = $this->getEnvironment();
        $inputProvider = $environment->getInputProvider();

        switch ($this->getSelectAction()) {
            case 'properties':
                if ($inputProvider->hasValue('models')) {
                    $models = $this->getModelIds($this->getEvent()->getAction(), $this->getSubmitAction());

                    $this->handleSessionOverrideEditAll($models, 'models');
                }

                break;

            case 'edit':
                if ($inputProvider->hasValue('properties')) {
                    $this->handleSessionOverrideEditAll($inputProvider->getValue('properties'), 'properties');
                }

                break;

            default:
        }
    }

    /**
     * Handle session data for override/edit all.
     *
     * @param array  $collection The collection.
     *
     * @param string $index      The session index for the collection.
     *
     * @return array The collection.
     */
    private function handleSessionOverrideEditAll(array $collection, $index)
    {
        $environment    = $this->getEnvironment();
        $dataDefinition = $environment->getDataDefinition();
        $sessionStorage = $environment->getSessionStorage();
        $submitAction   = $this->getSubmitAction(true);

        $session = array();
        if ($sessionStorage->has($dataDefinition->getName() . '.' . $submitAction)) {
            $session = $sessionStorage->get($dataDefinition->getName() . '.' . $submitAction);
        }

        // If collection not empty set to the session and return it.
        if (!empty($collection)) {
            $sessionCollection = array_map(
                function ($item) use ($index) {
                    if (!in_array($index, array('models', 'properties'))) {
                        return $item;
                    }

                    if (!$item instanceof ModelId) {
                        $item = ModelId::fromSerialized($item);
                    }

                    return $item->getSerialized();
                },
                $collection
            );

            $session[$index] = $sessionCollection;

            $sessionStorage->set($dataDefinition->getName() . '.' . $submitAction, $session);

            return $collection;
        }

        // If the collection not in the session return the collection.
        if (empty($session[$index])) {
            return $collection;
        }

        // Get the verify collection from the session and return it.
        $collection = array_map(
            function ($item) use ($index) {
                if (!in_array($index, array('models', 'properties'))) {
                    return $item;
                }

                return ModelId::fromSerialized($item);
            },
            $session[$index]
        );

        return $collection;
    }

    /**
     * This handle non edit action.
     *
     * @return void
     */
    private function handleNonEditAction()
    {
        $submitAction = $this->getSubmitAction();
        if (!in_array($submitAction, array('delete', 'copy', 'cut'))) {
            return;
        }

        switch ($submitAction) {
            case 'copy':
            case 'cut':
                $parameter = 'source';
                break;

            default:
                $parameter = 'id';
        }

        $modelIds = $this->getModelIds($this->getEvent()->getAction(), $submitAction);

        foreach ($modelIds as $modelId) {
            $this->getEnvironment()->getInputProvider()->setParameter($parameter, $modelId->getSerialized());
            $this->callAction($submitAction);
        }

        ViewHelpers::redirectHome($this->getEnvironment());
    }

    /**
     * If non select models or properties by show all action redirect to home.
     *
     * @return void
     *
     * @throws DcGeneralRuntimeException When the id is unparsable.
     */
    private function handleNonSelectByShowAllAction()
    {
        $submitAction = $this->getSubmitAction(true);
        if (in_array($submitAction, array('cut', 'delete', 'copy', 'override', 'edit'))) {
            return;
        }

        $environment   = $this->getEnvironment();
        $inputProvider = $environment->getInputProvider();
        $translator    = $environment->getTranslator();


        $modelIds = $this->getModelIds($this->getEvent()->getAction(), $submitAction);

        if ((empty($modelIds)
             && $inputProvider->getValue($submitAction) !== $translator->translate('MSC.continue'))
            || ($inputProvider->getValue($submitAction) === $translator->translate('MSC.continue')
                && !$inputProvider->hasValue('properties'))
        ) {
            ViewHelpers::redirectHome($this->getEnvironment());
        }
    }

    /**
     * Remove the global commands by action select.
     * We need the back button only.
     *
     * @return void
     */
    private function removeGlobalCommands()
    {
        $event          = $this->getEvent();
        $environment    = $event->getEnvironment();
        $dataDefinition = $environment->getDataDefinition();
        $view           = $dataDefinition->getDefinition('view.contao2backend');
        $globalCommands = $view->getGlobalCommands();

        foreach ($globalCommands->getCommands() as $globalCommand) {
            if (!($globalCommand instanceof BackCommand)) {
                $globalCommand->setDisabled();
            }
        }
    }

    /**
     * Handle the global commands.
     *
     * @return void
     */
    private function handleGlobalCommands()
    {
        $environment    = $this->getEnvironment();
        $dataDefinition = $environment->getDataDefinition();
        $backendView    = $dataDefinition->getDefinition(Contao2BackendViewDefinitionInterface::NAME);
        $globalCommand  = $backendView->getGlobalCommands();

        $backButton = null;
        if ($globalCommand->hasCommandNamed('back_button')) {
            $backButton = $globalCommand->getCommandNamed('back_button');
        }

        if (!$backButton) {
            return;
        }

        $parametersBackButton = $backButton->getParameters();

        if (in_array($this->getSelectAction(), array('properties', 'edit'))) {
            $parametersBackButton->offsetSet('act', 'select');
            $parametersBackButton->offsetSet('select', ($this->getSelectAction() === 'edit') ? 'properties' : 'models');
            $parametersBackButton->offsetSet('mode', $this->getSubmitAction(true));
        }

        $closeCommand = new Command();
        $globalCommand->addCommand($closeCommand);

        $closeExtra = array(
            'href'       => $this->getReferrerUrl(),
            'class'      => 'header_logout',
            'icon'       => 'delete.gif',
            'accessKey'  => 'x',
            'attributes' => 'onclick="Backend.getScrollOffset();"'
        );

        $closeCommand
            ->setName('close_all_button')
            ->setLabel('MSC.closeAll.0')
            ->setDescription('MSC.closeAll.1')
            ->setParameters(new \ArrayObject())
            ->setExtra(new \ArrayObject($closeExtra))
            ->setDisabled(false);
    }

    /**
     * Determine the correct referrer URL.
     *
     * @return mixed
     */
    private function getReferrerUrl()
    {
        $environment          = $this->getEnvironment();
        $parentDataDefinition = $environment->getParentDataDefinition();

        $event = new GetReferrerEvent(
            true,
            (null !== $parentDataDefinition)
                ? $parentDataDefinition->getName()
                : $environment->getDataDefinition()->getName()
        );

        $environment->getEventDispatcher()->dispatch(ContaoEvents::SYSTEM_GET_REFERRER, $event);

        return $event->getReferrerUrl();
    }

    /**
     * Get The model ids from the environment.
     *
     * @param Action $action       The dcg action.
     *
     * @param string $submitAction The submit action name.
     *
     * @return ModelId[]
     *
     * @throws DcGeneralRuntimeException When the id is unparsable.
     */
    private function getModelIds(Action $action, $submitAction)
    {
        $environment = $this->getEnvironment();
        $modelIds    = (array) $environment->getInputProvider()->getValue('models');

        if (!empty($modelIds)) {
            $modelIds = array_map(
                function ($modelId) {
                    return ModelId::fromSerialized($modelId);
                },
                $modelIds
            );

            $event = new PrepareMultipleModelsActionEvent($environment, $action, $modelIds, $submitAction);
            $environment->getEventDispatcher()->dispatch($event::NAME, $event);

            $modelIds = $event->getModelIds();
        }

        return $modelIds;
    }

    /**
     * Clear the clipboard by override/edit submit actions.
     *
     * @return void
     */
    private function clearClipboardBySubmitAction()
    {
        if (in_array($this->getSubmitAction(), array('edit', 'override'))) {
            return;
        }

        $this->clearClipboard();
    }

    /**
     * Clear the clipboard if has items.
     *
     * @return void
     */
    private function clearClipboard()
    {
        $environment        = $this->getEnvironment();
        $clipboard          = $environment->getClipboard();
        $basicDefinition    = $environment->getDataDefinition()->getBasicDefinition();
        $modelProviderName  = $basicDefinition->getDataProvider();
        $parentProviderName = $basicDefinition->getParentDataProvider();

        $filter = new Filter();
        $filter->andModelIsFromProvider($modelProviderName);
        if ($parentProviderName) {
            $filter->andParentIsFromProvider($parentProviderName);
        } else {
            $filter->andHasNoParent();
        }

        $items = $clipboard->fetch($filter);
        if (count($items) < 1) {
            return;
        }

        foreach ($items as $item) {
            $clipboard->remove($item);
        }
    }
}
