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
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @author     Stefan Heimes <stefan_heimes@hotmail.com>
 * @author     Andreas Isaak <andy.jared@googlemail.com>
 * @copyright  2013-2017 Contao Community Alliance.
 * @license    https://github.com/contao-community-alliance/dc-general/blob/master/LICENSE LGPL-3.0
 * @filesource
 */

namespace ContaoCommunityAlliance\DcGeneral\DataDefinition\Definition\View;

/**
 * Implementation of a "back" command.
 */
class BackCommand extends Command
{
    /**
     * Create a new instance.
     */
    public function __construct()
    {
        parent::__construct();
        $extra               = $this->getExtra();
        $extra['class']      = 'header_back';
        $extra['accesskey']  = 'b';
        $extra['attributes'] = 'onclick="Backend.getScrollOffset();"';
        $this
            ->setName('back_button')
            ->setLabel('MSC.backBT')
            ->setDescription('MSC.backBT');
    }
}
