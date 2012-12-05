<?php

class WonderCard {
	protected $name;
	protected $color;
	protected $moneyCost;
	protected $resourceCost;
	protected $age;
	protected $numPlayers;
	protected $command;
	protected $playFunc;
	protected $freePrereq;

	function __construct($args){
		$this->name = $args['name'];
		$this->color = $args['color'];
		$this->moneyCost = $args['moneyCost'];
		$this->resourceCost = $args['resourceCost'];
		$this->age = $args['age'];
		$this->freePrereq = $args['freePrereq'];
		$this->numPlayers = $args['numPlayers'];
		$this->command = $args['command'];
	}

	function getName(){
		return $this->name;
	}

	function getColor(){
		return $this->color;
	}

	function getMoneyCost(){
		return $this->moneyCost;
	}

	function getResourceCost(){
		return $this->resourceCost;
	}

	function getCommand(){
		return $this->command;
	}

	function getAge(){
		return $this->age;
	}

	function getNumPlayers(){
		return $this->numPlayers;
	}

	function getPrereq(){
		return $this->freePrereq;
	}

	function play($user, $args){
		if($this->moneyCost != 0)
			$user->addCoins(-1 * $this->moneyCost);
		// calculate resources/buying from neighbors here
		// include temp resources
		return parseFunc($this->command, $this, $user, $args);
	}

	public function __call($closure, $args)
    {
        call_user_func_array($this->$closure, $args);
    }
}

class WonderDeck {
	public $cards = array();
	protected $hands = array();

	public function addCard($args){
		if(is_array($args['numPlayers'])){
			$args_copy = $args;
			foreach($args['numPlayers'] as $numPlayers){
				/* IMPORTANT IMPORTANT IMPORTANT
				UNCOMMENT THE LINE SO NUMPLAYERS WORKS WHEN IN ACTUAL TESTING */
				$args_copy['numPlayers'] = 1; //$numPlayers;
				$this->cards[] = new WonderCard($args_copy);
			}
		} else {
			$args['numPlayers'] = 1;
			$this->cards[] = new WonderCard($args);
		}
	}

	public function cardInfo($cards, $players = array()){
		$info = array();
		foreach($cards as $id => $card){
			$cInfo = array(
				'name' => $card->getName(),
				'color' => $card->getColor(),
				'id' => $id
			);
			foreach($players as $pl){
				if($pl->getId() == $id and ($pl->isTrashing or $pl->isBuildWonder)) $cInfo['trashing'] = true;
			}
			$info[] = $cInfo;
		}
		return $info;
	}

	public function deal($age, $players){
		$numplayers = count($players);
		$playableCards = array();

		if($age == 3){
			$guilds = array();
			foreach($this->cards as $card){
				if(strpos(strtolower($card->getName()), "guild") !== false){
					$guilds[] = $card;
				}
			}
			shuffle($guilds);
			for($i = 0; $i < $numplayers + 2; $i++){
				$playableCards[] = $guilds[$i];
			}
		}

		foreach($this->cards as $card)
			if($card->getNumPlayers() <= $numplayers and
			   $card->getAge() == $age and 
			   strpos(strtolower($card->getName()), "guild") === false) 
				$playableCards[] = $card;

		shuffle($playableCards);

		foreach($players as $player){
			$hand = array_splice($playableCards, 0, 7);
			$this->hands[$player->getId()] = $hand;
			$player->hand = $hand;
			$packet = packet(array('cards' => $this->cardInfo($hand, array()), 'age' => $age), "hand");
			$player->sendString($packet);
		}
	}
}

$deck = new WonderDeck();

function getResourceCost($str){
	$resources = array(
		'S' => 'stone',
		'T' => 'wood',
		'W' => 'wood',
		'O' => 'ore',
		'C' => 'clay',
		'L' => 'linen',
		'G' => 'glass',
		'P' => 'paper'
	);
	$cost = array();
	for($i = 0; $i < strlen($str); $i++){
		if(isset($resources[$str[$i]]))
			$cost[$resources[$str[$i]]] = isset($cost[$resources[$str[$i]]]) ? $cost[$resources[$str[$i]]] + 1 : 1;
	}
	return $cost;
}

function getNumPlayers($fields){
	$numplayers = array();
	$last = 0;
	for($i = 7; $i <= 11; $i++){
		$num = intval($fields[$i]);
		if($num > $last){
			$numplayers[] = $i - 4;
			$last = $num;
		}
	}
	return $numplayers;
}



function parseFunc($command, $card, $player, $ignore_this){
	switch($card->getColor()){
		case 'red':
			$player->military += intval($command);
		break;

		case 'blue':
			$player->points += intval($command);
		break;

		case 'yellow':
			if($card->getAge() == 1){
				if(preg_match('/[0-9]/', $command))
					$player->addCoins(intval($command));
				else {
					$args = explode(' ', $command);
					$directions = arrowsToDirection($args[0]);
					$resources = getResourceCost($args[1]);
					foreach($resources as $resource => $amount){
						foreach($directions as $dir){
							$player->discounts[$dir][$resource] = !isset($player->discounts[$dir][$resource]) ? 1 
																	: $player->discounts[$dir][$resource] + 1;
						}
					}
				}
			} elseif($card->getAge() == 2){
				// check for yellow non-buyable resources
				if(strpos($command, '/') !== false){
					$resources = getResourceCost($command);
					$player->addResource(array_keys($resources), 1, false);
				} else {
					$args = explode(' ', $command);
					$directions = arrowsToDirection($args[0]);
					$coins = 0;
					$color = $args[1];
					$mult = intval($args[2]);
					foreach($directions as $dir){
						$pl = $dir == 'left' ? $player->leftPlayer : ($dir == 'right' ? $player->rightPlayer : $player);
						foreach($pl->cardsPlayed as $c){
							if($c->getColor() == $color) $coins += $mult;
						}
					}
					$player->addCoins($coins);
				}
			} elseif($card->getAge() == 3){
				preg_match('/\((.)\)\{(.)\} (.+)?/', $command, $matches);
				// when/how do we want to evaluate points??
				$coins = $matches[1]; $color = $matches[3];
				$coinsToGive = 0; 
				if($color == 'wonder'){
					// check for wonder construction here
				} else {
					foreach($player->cardsPlayed as $card){
						if($card->getColor() == $color){
							$coinsToGive += $coins;
						}
					}
				}
				if($coinsToGive > 0) $player->addCoins($coinsToGive);
			}
		break;

		case 'green':
			$player->addScience(intval($command));
		break;

		case 'purple':
			if($card->getName() == 'Scientists Guild'){
				$player->addScience(0);
			}
			// none of these give coins or anything, so don't need to check
			// until the end
		break;

		case 'brown': case 'grey':
			// CHECK FOR DOUBLE RESOURCE VS TWO OPTION RESOURCE
			$resources = getResourceCost($command);
			if(strpos($command, '/') !== false){
				$player->addResource(array_keys($resources), 1, true);
			} else {
				foreach($resources as $resource => $amount){
					$player->addResource($resource, $amount, true);
				}
			}
		break;
	}
}

function importCards($age){
	global $deck;
	foreach(explode("\r", file_get_contents("cards/age" . $age . ".csv")) as $line){
		$fields = str_getcsv($line);
		$deck->addCard(array(
			'name' => $fields[3],
			'color' => $fields[2],
			'moneyCost' => preg_match('/[0-9]/', $fields[1], $matches) ? 1 : 0,
			'resourceCost' => getResourceCost($fields[1]),
			'freePrereq' => $fields[0],
			'age' => $age,
			'numPlayers' => $fields[2] == 'purple' ? array(1) : getNumPlayers($fields),
			'command' => $fields[4]
		));
	}
}

for($i = 0; $i < 3; $i++)
	importCards($i);

?>