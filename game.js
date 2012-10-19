var SevenWonders = function(socket, args){
	this.wonder = args.wonder;
	this.players = args.plinfo;
	this.coins = parseInt(args.coins);
	this.socket = socket;

	$('#wondername').html(this.wonder);
}

SevenWonders.prototype = {
	send: function(opts, type){
		opts = typeof opts == "object" ? opts : {value: opts};
		opts.messageType = type;
		this.socket.send(JSON.stringify(opts));
	},

	onMessage: function(args, msg){
		switch(args.messageType){
			case 'hand':
				args.cards = $.map(args.cards, function(k,v){ return [k]; });

				$('#hand').html(''); // more fancy card removal effects here
				for(i in args.cards){
					var card = args.cards[i];
					$('#hand').append('<div class="card"><h1>' + card + '</h1></div>');
				}

				var numCards = args.cards.length;
				$('.card').each(function(){
					var deg = ($(this).index() + 0.5 - numCards / 2) * 10;
					$(this).css({
						'-webkit-transform': 'rotate(' + deg + 'deg)',
						'margin-top': Math.pow($(this).index() + 0.5 - numCards / 2, 2) * 10
					});
					$(this).data('rotation', deg);
				});

				$('.card').css('margin-left', numCards <= 4 ? 20 : (numCards - 4) * -25);

				var self = this;
				$('.card').click(function(){ 
					$('.card').removeClass('selected');
					$(this).addClass('selected');
					self.send($(this).find('h1').html(), 'cardplay');
				});

				// work on active animations!
				/*$('.card').click(function(){
					$(this).animate({ left: '-100px' }, 200, function(){
						$(this).css('z-index', '2');
						$(this).rotate({
							angle: $(this).data('rotation'),
							animateTo: 0,
							duration: 500
						});
						$(this).animate({
							left: $('#hand').outerWidth() / 2 - 200 - Math.min($(this).position().left, 0)
						}, 200);
					});
				});*/
			break;

			case 'cardschosen':
				$('#lastplayed ul').html('');
				for(id in args.cards){
					var pl;
					for(i in this.players) 
						if(this.players[i].id == id) pl = this.players[i];
					$('#lastplayed ul').append('<li>' + pl.name + ' - ' + args.cards[id] + '</li>');
				}
				console.log('chosen', args);
			break;

			default:
				console.log(args, msg);
			break;
		}
	}
}