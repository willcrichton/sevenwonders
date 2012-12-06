<?php

class Resource {
    const STONE = 0;
    const WOOD  = 1;
    const ORE   = 2;
    const CLAY  = 3;
    const LINEN = 4;
    const GLASS = 5;
    const PAPER = 6;

    private $amts = array(self::STONE => 0, self::WOOD => 0, self::ORE => 0,
                          self::CLAY => 0, self::LINEN => 0, self::GLASS => 0,
                          self::PAPER => 0);
    private $only_one = false;
    private $_buyable = false;

    public function __construct($only_one, $buyable) {
        $this->only_one = $only_one;
        $this->_buyable = $buyable;
    }

    public function buyable() {
        return $this->_buyable;
    }

    public function add($resource) {
        $this->amts[$resource]++;
    }

    public static function one($res) {
        $ret = new Resource(false, false);
        $ret->add($res);
        return $ret;
    }

    public static function satisfiable($cost, $have) {
        return count(self::satisfy($cost, $have)) == 0;
    }

    public static function satisfy($cost, $have) {
        $total = array(self::STONE => 0, self::WOOD => 0, self::ORE => 0,
                       self::CLAY => 0, self::LINEN => 0, self::GLASS => 0,
                       self::PAPER => 0);
        foreach ($cost as $resource) {
            foreach ($resource->amts as $res => $amt) {
                $total[$res] += $amt;
            }
        }

        $ret = array();
        self::tryuse($have, $total, $ret);
        return $ret;
    }

    private static function tryuse($resources, $costs, &$ret) {
        $allZero = true;
        foreach ($costs as $amount) {
            if ($amount > 0) {
                $allZero = false;
                break;
            }
        }
        if ($allZero) {
            $ret = array();
            return true;
        }
        if (count($resources) == 0) {
            $ret[] = $costs;
            return false;
        }

        $resource = array_pop($resources);
        if ($resource->only_one) {
            // If we can only use one of these resources, try each one
            // individually and see if we can satisfy
            $tried = false;
            foreach ($resource->amts as $type => $amt) {
                if ($costs[$type] <= 0) continue;
                $tried = true;
                $costs[$type] -= $amt;
                if (self::tryuse($resources, $costs, $ret)) return true;
                $costs[$type] += $amt;
            }
            // Otherwise try just not using this resource
            return $tried ? false : self::tryuse($resources, $costs, $ret);
        }

        // If we can use this multi-resource, then use as much of it as possible
        // and then move on to using another resource.
        foreach ($resource->amts as $type => $amt) {
            if ($costs[$type] > 0) $costs[$type] -= $amt;
        }

        return self::tryuse($resources, $costs, $ret);
    }
}

class Science {
    const ANY     = 0;
    const GEAR    = 1;
    const COMPASS = 2;
    const TABLET  = 3;

    private $amts = array(self::ANY => 0, self::GEAR => 0, self::COMPASS => 0,
                          self::TABLET => 0);

    public function add($science) {
        $this->amts[$science]++;
    }

    public function points() {
        if ($this->amts[self::ANY] > 0) {
            $max = 0;
            $this->amts[self::ANY]--;
            foreach (array(self::GEAR, self::COMPASS, self::TABLET) as $s) {
                $this->amts[$s]++;
                $max = max($max, $this->points());
                $this->amts[$s]--;
            }
            $this->amts[self::ANY]++;
            return $max;
        }

        $min = 1000;
        $points = 0;
        foreach ($this->amts as $typ => $amt) {
            if ($typ == self::ANY) continue;
            $points += $amt * $amt;
            $min = min($min, $amt);
        }
        return $points + $min * 7;
    }
}

class Military {
    private $_size = 0;
    private $amt = array(-1 => 0, 1 => 0, 3 => 0, 5 => 0);

    public function fight($other, $age) {
        if ($other->_size > $this->_size) {
            $this->amt[-1]++;
        } else if ($other->_size < $this->_size) {
            $this->amt[2 * $age - 1]++;
        }
    }

    public function points() {
        $sum = 0;
        foreach ($this->amt as $mult => $amt) {
            $sum += $mult * $amt;
        }
        return $sum;
    }

    public function add($amt) { $this->_size += $amt; }
    public function json() { return $this->amt; }
    public function size() { return $this->_size; }
    public function losses() { return $this->amt[-1]; }
}
