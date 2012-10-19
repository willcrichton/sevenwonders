<!DOCTYPE html>
<html>
	<head>
		<title>Test</title>
		<link href="style.css" rel="stylesheet" type="text/css" />
		<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1/jquery.min.js"></script>
		<script type="text/javascript" src="jquery-rotate.js"></script>
		<script type="text/javascript" src="game.js"></script>
		<script type="text/javascript">
			function createSocket(host){
				if(window.WebSocket) return new WebSocket(host);
				else if(window.MozWebSocket) return new MozWebSocket(host);
			}

			function send(opts, type){
				opts.messageType = type;
				socket.send(JSON.stringify(opts));
			}

			var socket;
			var my_name;
			var players = [];
			var status = "lobby";
			var game = undefined;
			$(document).ready(function(){
				$("#game").css({width: $(window).width(), height: $(window).height()});
				var host = "ws://" + window.location.host + ":12345";
				try {
					socket = createSocket(host);
					socket.onopen = function(msg){ 
						console.log("WebSocket OPEN!"); 
						if(localStorage.sevenwonders_name != ''){
							changeName({name:{value: localStorage.sevenwonders_name}});
							$('input[name=name]').attr('value', localStorage.sevenwonders_name);
						}
					}
					socket.onmessage = function(msg){ 
						var args = {};
						try {
							var args = JSON.parse(msg.data);
						} catch(ex){}
						
						switch(args.messageType){
							case 'myname':
								if(localStorage.sevenwonders_name == ''){
									$('input[name=name]').attr('value', args.data);
									my_name = args.data;
								}
							break;

							case 'newgame':
								if(status == "lobby"){
									$('#opengames').append('<li>' + args.name + ' - ' + args.creator + 
										' (<a href="#" onclick="return joinGame(' + args.id + ')">Join</a>)</li>');
								}
							break;

							case 'newplayer':
								players.push(args);
								$('#waiting ul').append('<li>' + args.name + '</li>');
							break;

							case 'startinfo':
								status = "game";
								$('#pregame').fadeOut(500);
								$('#game').fadeIn(500);

								game = new SevenWonders(socket, args);
								console.log(game);
							break;

							default:
								if(game != undefined) 
									game.onMessage(args, msg.data);
								else
									console.log(msg.data);
							break;
						}
					}
					socket.onclose = function(msg){ console.log("WebSocket CLOSED..."); }
				} catch(ex) {
					console.log(ex);
				}

				$('input[name=players]').change(function(){ 
					$('#numplayers').html($(this).attr('value'));
				});
			});

			/*$(document).on('mouseenter', '.card', function(){
				$(this).animate({ bottom: '+=25' }, 100);
			});

			$(document).on('mouseleave', '.card', function(){
				$(this).animate({ bottom: '-=25' }, 100);
			})*/

			function newGame(form){
				if(form.game_name.value == ''){
					alert('Please enter a name for your game.');
					return false;
				}
				var opts = { name: form.game_name.value, players: form.players.value };
				send(opts, "newgame");
				waitScreen();

				return false;
			}

			function waitScreen(){
				$('#lobby').fadeOut(500, function(){
					$('#waiting').fadeIn(1000);
					$('#pregame').animate({ height: $('#waiting').outerHeight() }, 500, function(){
						$(this).css('height', 'auto');
					});
				});

				$('#waiting ul').append('<li>' + my_name + ' (you)</li>');
				$('#pregame').css('height', $('#lobby').outerHeight());

				status = "waiting";
			}

			function joinGame(id){
				var opts = { id: id };
				send(opts, "joingame");
				waitScreen();

				return false;
			}

			function changeName(form){
				var opts = { name: form.name.value };
				send(opts, "changename");
				my_name = opts.name;
				localStorage.sevenwonders_name = my_name;
				return false;
			}
		</script>
	</head>
	<body>	
		<div id="pregame">
			<div id="lobby">
				<h1>Lobby</h1>
				<form id="nickname" method="post" onsubmit="return changeName(this)">
						Name: <input type="text" name="name" value="" /> 
						<input type="submit" value="Change Name" />
				</form>
				<div class="column left">
					<h2>Start a Game</h2>
					<form method="post" onsubmit="return newGame(this)">
						<table>
							<tr>
								<td>Name:</td>
								<td><input type="text" name="game_name" /></td>
							</tr>
							<tr>
								<td>Players:</td>
								<td><input type="range" name="players" min="1" max="7" value="1" /> <span id="numplayers">1</span></td>
							</tr>
							<tr>
								<td></td>
								<td><input type="submit" value="Start" /></td>
							</tr>
						</table>
					</form>
				</div>
				<div class="column right">
					<h2>Open Games</h2>
					<ul id="opengames"></ul>
				</div>
			</div>
			<div id="waiting">
				<h1>Waiting for Players...</h1>
				<ul></ul>
			</div>
		</div>
		<div id="game">
			<div id="hand"></div>
			<div id="myboard">
				<div id="info">
					<h2>My Info</h2>
					Wonder: <span id="wondername"></span><br />
					Coins: <span id="coins">3</span>
				</div>
				<div id="lastplayed">
					<h2>Previously Played</h2>
					<ul></ul>
				</div>
			</div>
		</div>
		<!-- co.nr link for niceness -->
		<a href="http://www.freedomain.co.nr/" title="Free Domain Name"><img src="http://rnrnoza.imdrv.net/animg7.gif" alt="Free Domain Name" style="width:88px;height:31px;border:0;" /></a>
	</body>
</html>			