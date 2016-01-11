<?php

/**
 * Gearman Bundle for Symfony2
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 */

namespace Mmoreram\GearmanBundle\Helper;

/**
* Gearman tcp helper
*
* This class provides with basic TCP connection needs
 *
* @author Martynas Boika <mmartynasb@gmail.com>
*/
class TCPHelper
{
    /**
    * Checking connection for given server
    *
    * @param string $host ip
    * @param int $port
    * @param float $timeout connection timeout
    * @return boolean
    */
    public static function tryConnection($host, $port = 80, $timeout = 0.1)
    {
        if ($fp = @fsockopen($host, $port, $errno, $errstr, $timeout)) {
            fclose($fp);
            return true;
        }

        return false;
    }
}