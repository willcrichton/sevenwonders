<?
/* * * * * * * * * * * * * * * * * * * * * * * * * *
 * TO DO LIST
 * 1. Fix cards
 *	 1a. Add in effects for wonders (where applicable)
 * 2. Finish wonders
 *   2b. Let players choose a side
 *   2c. Let players build wonder stages
 *   2e. Add functionality to wonder stages
 * 3. Add neighbors
 *   3c. Add interface to see info about non-neighbors
 *	 3d. Show which resource neighbors have
 * 7. Transition between ages
 * 8. End game
 *    8a. Calculate victory points
 *    8b. Animation/show victor
 * 9. Button to show all your current cards
 * 10. Highlight play for free cards
 * 11. Show victory points on screen somewhere
 * * * * * * * * * * * * * * * * * * * * * * * * * */

require_once("cards.php");

class SevenWonders {
	public $debug = true;
	public $name;
	public $maxplayers = 7;
	public $players = array();
	public $creator;
	public $id;
	public $deck;
	public $server;
	public $started = false;
	public $age = 1;
	public $turn = 1;
	public $cardsChosen = array();
	public $wonders = array(
		array(
			"name" => "Olympia",
			"resource" => "wood"
		), 
		array(
			"name" => "Rhodos",
			"resource" => "ore",
		),
		array(
			"name" => "Alexandria",
			"resource" => "glass"
		),
		array(
			"name" => "Ephesos",
			"resource" => "paper"
		),
		array(
			"name" => "Halikarnassus",
			"resource" => "linen"
		),
		array(
			"name" => "Gizah",
			"resource" => "stone"
		),
		array(
			"name" => "Babylon",
			"resource" => "clay"
		)
	);

	public function __construct(){
		global $deck;
		$this->deck = $deck;
	}

	public function log($msg){
		if($this->debug) 
			echo "Game " . $this->id . " (turn " . $this->turn . ", age " . $this->age . "): $msg\n";
	}

	public function addPlayer(IWebSocketConnection $user){
		if(count($this->players) == 0) $this->creator = $user;
		$this->players[] = $user;
		$user->game = $this;
		
		$packet = packet(array('id' => $user->getId(), 'name' => $user->name), 'newplayer');
        foreach($this->players as $player){
                if($player != $user){
                        $player->sendString($packet);   
                        $user->sendString(packet(array('id' => $player->getId(), 'name' => $player->name), 'newplayer'));   
                } 
        }

        // tell the players we're starting the game
        if(count($this->players) == $this->maxplayers){
        	$this->started = true;
        	$packet = packet(array('id' => $this->id), 'started');
         	$this->server->broadcastAll($packet);

         	// give clients time to receive start signal
         	sleep(0.1);

            // set up the start conditions
			$wonderKeys = array_keys($this->wonders);
			foreach($this->players as $player){
				// starting moneyz
				$player->coins = 3;

				// select a wonder
				$wonder = array_rand($wonderKeys);
				$player->wonder = $this->wonders[$wonderKeys[$wonder]];
				$player->addResource($player->wonder['resource'], 1);
				unset($wonderKeys[$wonder]);
			}

			// shuffle order of players
			shuffle($this->players); 
			$playerInfo = array();
			for($i = 0; $i < count($this->players); $i++){
				$this->players[$i]->leftPlayer = $i == 0 ? $this->players[count($this->players) - 1] : $this->players[$i - 1];
				$this->players[$i]->rightPlayer = $i == (count($this->players) - 1) ? $this->players[0] : $this->players[$i + 1];
				$this->players[$i]->order = $i + 1;
				$playerInfo[] = array(
					'id' => $this->players[$i]->getId(),
					'name' => $this->players[$i]->name,
					'order' => $this->players[$i]->order
				);
			}

			// send start information
			foreach($this->players as $player){
				$startInfo = array(
					"coins" => 3,
					"wonder" => $player->wonder["name"],
					"plinfo" => $playerInfo,
					"resource" => $player->wonder['resource'],
					"neighbors" => array('left' => $player->leftPlayer->getId(), 'right' => $player->rightPlayer->getId())
				);
				$player->sendString(packet($startInfo, "startinfo"));
			}

			// deal the cards
			$this->deck->deal(1, $this->players);
        }
	}

	public function getPlayerById($id){
		foreach($this->players as $player)
			if($player->getId() == $id) return $player;
	}

	public function removePlayer(IWebSocketConnection $user){
		// drop player from game
		unset($this->players[array_search($user, $this->players)]);
	}

	public function broadcast($packet, $exclude = false){
		foreach($this->players as $player) 
			if(!$exclude || $player != $exclude) $player->sendString($packet);
	}

	public function rotateHands($moveLeft){
		// todo: simiplify this so it uses $player->leftNeighbor and rightNeighbor
		if($moveLeft){
			$temp = $this->players[count($this->players) - 1]->hand;
			for($i = count($this->players) - 1; $i >= 0; $i--){
				$this->players[$i]->hand = $i == 0 ? $temp : $this->players[$i - 1]->hand;
				$packet = packet(array('cards' => $this->deck->cardInfo($this->players[$i]->hand)), "hand");
				$this->players[$i]->sendString($packet);
			}
		} else {
			$temp = $this->players[0]->hand;
			for($i = 0; $i < count($this->players); $i++){
				$this->players[$i]->hand = $i == count($this->players) - 1 ? $temp : $this->players[$i + 1]->hand;
				$packet = packet(array('cards' => $this->deck->cardInfo($this->players[$i]->hand)), "hand");
				$this->players[$i]->sendString($packet);
			}
		}
	}

	public function onMessage(IWebSocketConnection $user, $args){
		switch($args['messageType']){
			case 'cardplay':
				$cardName = $args['value'][0];
				// match what they sent with a Card object
				foreach($user->hand as $card)
				{
					if($card->getName() == $cardName) $foundCard = $card;
				}
				if(isset($foundCard)){
					if($args['value'][1] == 'trash' or $user->canPlayCard($foundCard, true)){
						$this->log("User " . $user->name . " (" . $user->getId() . ") chose " . $foundCard->getName());
						$user->isTrashing = $args['value'][1] == 'trash';
						$this->cardsChosen[$user->getId()] = $foundCard;
						$user->selectedCard = $foundCard;
						$user->sendString(packet('','canplay'));
						// if everyone's played a card, execute cards and redeal/new turn
						if(count($this->cardsChosen) == count($this->players)){
							$this->log("All cards found");
							$this->broadcast(packet(array('cards' => $this->deck->cardInfo($this->cardsChosen, $this->players)), "cardschosen"));

							// execute effects of all played cards
							foreach($this->players as $player){
								if($player->isTrashing == true){
									$this->log("User " . $user->name . " (" . $user->getId() . ") trashing " . $player->selectedCard->getName());
									$player->addCoins(3);
									$player->isTrashing = false;
								//} elseif($player->isWonderBuilding == true){
									// lucy's code here
									/*check -> resources -> please 
									do they have logs and shit like that (are those the resources???) jk shit = false !!
									player PURPLE --> wonder -> BUILD. 
									notify everyone else. GOGOGOOOOO
									player PURPLE -> wonder -> use effect pink noooo why do PINK */
								} else {
									$this->log("User " . $player->name . " (" . $user->getId() . ") playing " . $player->selectedCard->getName());										
									$player->selectedCard->play($player, array() /*, more args here? */);
									$player->cardsPlayed[] = $player->selectedCard;
								}
								unset($player->hand[array_search($player->selectedCard, $player->hand)]);
								unset($player->selectedCard);
								$player->tempResources = array();
							}

							$this->cardsChosen = array();
							if($this->turn == 6){
								// go into a new age
								$this->log("Ending age " . $this->age);

								$this->log("Evaluating military");
								foreach($this->players as $player){
									$player->evaluateMilitary($this->age);
								}

								$this->age++;
								$this->turn = 1;
								$this->deck->deal($this->age, $this->players);
							} else {
								// change hands and start a new turn
								$this->log("Ending turn " . $this->turn);
								$this->turn++;
								$this->rotateHands($this->age != 2);
							}

							foreach($this->players as $player){
								$this->log("User " . $player->name . " (" . $player->getId() . ") has " . $player->calcPoints() . " points");
							}
						}
					} else {
						// error: user can't play the card
					}
				} else {
					// error: you cheating motherfucker, you don't have that card in your hand
				}
			break;

			case 'cardignore':
				$user->isTrashing = false;
				unset($user->selectedHard);
				unset($this->cardsChosen[$user->getId()]);
			break;

			case 'trade':
				$this->log("User " . $user->name . " is trading");
				$leftTotal = 0;

				$leftFilter = array_filter($user->leftPlayer->permResources, function($var){ return $var->buyable; });
				if(!$user->leftPlayer->checkResourceCost($args['left'], $leftFilter)){
					$user->sendError("Left player doesn't have those resources");
					return;
				}
				foreach($args['left'] as $resource => $amount){
					$discount = isset($user->discounts['left'][$resource]) ? $user->discounts['left'][$resource] : 0;
					$leftTotal += max((2 - $discount) * $amount, 0);
				}

				$rightTotal = 0;
				$rightFilter = array_filter($user->rightPlayer->permResources, function($var){ return $var->buyable; });
				if(!$user->leftPlayer->checkResourceCost($args['right'], $rightFilter)){
					$user->sendError("Right player doesn't have those resources");
					return;
				}
				foreach($args['right'] as $resource => $amount){
					$discount = isset($user->discounts['right'][$resource]) ? $user->discounts['right'][$resource] : 0;
					$rightTotal += max((2 - $discount) * $amount, 0);
				}

				$total = $leftTotal + $rightTotal;
				// factor in yellow cards into total cost here
				if($total > $user->coins){
					$user->sendError("You don't have enough money to buy those resources");
					return;
				}

				$user->addCoins(-1 * $total);
				if($leftTotal > 0) $user->leftPlayer->addCoins($leftTotal);
				if($rightTotal > 0) $user->rightPlayer->addCoins($rightTotal);
				// give left/right neighbors coins accordingly
				foreach(array('left', 'right') as $side){
					foreach($args[$side] as $resource => $amount){
						$user->tempResources[$resource] = $amount;
					}
				}

				$user->sendString(packet(array('resources' => $user->tempResources), 'bought'));
			break;

			default:
				echo "Undefined message\n";
				print_r($args);
			break;
		}
	}
}

?>

