<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PageBundle\Generator;

/**
 * Render a string using the mustache formatter : {{ var }}.
 *
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * @final since sonata-project/page-bundle 3.26
 */
class Mustache
{
    /**
     * @static
     *
     * @param $string
     *
     * @return string
     */
    public static function replace($string, array $parameters)
    {
        $replacer = static function ($match) use ($parameters) {
            return $parameters[$match[1]] ?? $match[0];
        };

        return preg_replace_callback('/{{\s*(.+?)\s*}}/', $replacer, $string);
    }
}
