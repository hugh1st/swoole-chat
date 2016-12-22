$(document).ready(function(){
	face.init();	//自定义表情
	chat.init();
});

var chat = {
	data : {
		wSock       : null,
		login		: false,
		storage     : null,
		type	    : 1,
		fd          : 0,
		token        : "",
		wxid       : "",
		avatar      : "",
		rds         : [],//所有房间ID
		crd         : 'a', //当前房间ID
		remains     : []
	},
	init : function (){
		console.log("init");
		this.copyright();
		this.off();
		chat.data.storage = window.localStorage;
		var height = $(window).height() + 'px';
		$('#body').css({'height' : height});
		this.ws();
	},
	doLogin : function( token, wxid ){
		console.log("doLogin");
		if(token == '' || wxid == ''){
			token =  $("#token").val();
			wxid = $('#wxid').val();
		}
		token = $.trim(token) ;
		wxid = $.trim(wxid) ;
		if(token == "" || wxid == ""){
			chat.displayError('chatErrorMessage_logout',"您没有获得授权",1);
			return false;
		}
		//登录操作
		chat.data.type = 1; //登录标志
		chat.data.wxid = wxid; //邮箱
		chat.data.login = true;
		var json = {"type": chat.data.type,"token": token,"wxid": wxid,'roomid':'a'};
		chat.wsSend(JSON.stringify(json));
		return false;
		 
	},
	logout : function(){
		console.log("logout");
		if(!this.data.login) return false;
		chat.data.type = 0;
		chat.data.storage.removeItem('dologin');
		chat.data.storage.removeItem('token');
		chat.data.storage.removeItem('wxid');
		chat.data.fd = '';
		chat.data.token = '';
		chat.data.avatar = '';
		location.reload() ;
	},
	keySend : function( event ){
		console.log("keySend");
		if (event.ctrlKey && event.keyCode == 13) {
			$('#chattext').val($('#chattext').val() +  "\r\n");
		}else if( event.keyCode == 13){
			event.preventDefault();//避免回车换行
			this.sendMessage();
		}
	},
	sendMessage : function(){	
		console.log("sendMessage");
		if(!this.data.login) return false;
		//发送消息操作
		var text = $('#chattext').val();
		if(text.length == 0) return false;
		chat.data.type = 2; //发送消息标志
		var json = {"type": chat.data.type,"token": chat.data.token,"avatar": chat.data.avatar,"message": text,"c":'text',"roomid":this.data.crd};
		console.log("sendMessage:");
		console.log(json);
		chat.wsSend(JSON.stringify(json));
		return true;
	},
	ws : function(){
		console.log("ws");
		this.data.wSock = new WebSocket(config.wsserver);
		this.wsOpen();
		this.wsMessage();
		this.wsOnclose();
		this.wsOnerror();
	},
	wsSend : function(data){
		console.log("wsSend:");
		console.log(data);
		this.data.wSock.send(data);
	},
	wsOpen : function (){
		console.log("wsOpen");
		this.data.wSock.onopen = function( event ){
			//初始化房间
			chat.print('wsopen',event);
			token = "1";
			wxid = "2";
			chat.doLogin( token , wxid );
		}
	},
	wsMessage : function(){
		console.log("wsMessage");
		//当Browser接收到WebSocketServer发送过来的数据时，就会触发onmessage消息，参数evt中包含server传输过来的数据;
		this.data.wSock.onmessage = function(event){
			var d = jQuery.parseJSON(event.data);
			console.log(event.data);
			switch(d.code){
				case 1:	//登录
					alert("登录" + JSON.stringify(d.data));
					if(d.data.mine){
						chat.data.fd = d.data.fd;
						chat.data.token = d.data.token;
						chat.data.avatar = d.data.avatar;
						chat.data.storage.setItem("dologin",1);
						chat.data.storage.setItem("token",d.data.token);
						chat.data.storage.setItem("wxid",chat.data.wxid);
						document.title = d.data.token + '-' + document.title;
						chat.loginDiv(d.data);
					} 
					chat.addChatLine('newlogin',d.data,d.data.roomid);
					chat.addUserLine('user',d.data);
					chat.displayError('chatErrorMessage_login',d.msg,1);
					break;
				case 2:	//发送新消息
					if(d.data.mine){
						chat.addChatLine('mymessage',d.data,d.data.roomid);
						$("#chattext").val('');
					} else {
						if(d.data.remains){
							for(var i = 0 ; i < d.data.remains.length;i++){
								if(chat.data.fd == d.data.remains[i].fd){
									chat.shake();
									var msg = d.data.token + "在群聊@了你。";
									chat.displayError('chatErrorMessage_logout',msg,0);
								}
							}
						}
						chat.chatAudio();
						chat.addChatLine('chatLine',d.data,d.data.roomid);
					}
					break;
				case 3:	//退出
					chat.removeUser('logout',d.data);
					if(d.data.mine && d.data.action == 'logout'){
						
						return;
					}
					chat.displayError('chatErrorMessage_logout',d.msg,1);
					break;
				case 4: //页面初始化
					chat.initPage(d.data);
					break;
				case 5:	//未登录
					if(d.data.mine){
						chat.displayError('chatErrorMessage_logout',d.msg,1);
					}
					break;
				case 6:	//换房成功
					if(d.data.mine){
						//如果是自己
						
					} else {
						//如果是其他人
						
					}
					//删除旧房间该用户
					chat.changeUser(d.data);
					chat.addUserLine('user',d.data);
					break;
				default :
					chat.displayError('chatErrorMessage_logout',d.msg,1);
			}
		}
	},
	wsOnclose : function(){
		console.log("wsOnclose");
		this.data.wSock.onclose = function(event){
			
		}
	},
	wsOnerror : function(){
		console.log("wsOnerror");
		this.data.wSock.onerror = function(event){
			alert('服务器开小差了，请联系我们：e_dao@qq.com');
		}
	},
	/** 
	 * 当一个用户进来或者刷新页面触发本方法
	 *
	 */
	initPage:function( data ){
		console.log("initPage");
		this.initRooms( data.rooms );
		this.initUsers( data.users );
	},
	/**
	 * 填充房间用户列表
	 */
	initUsers : function( data ){
		console.log("initUsers");
		if(getJsonLength(data)){
			for(var item in data){
				var users = [];
				var len = data[item].length;
				if(len){
					for(var i = 0 ; i < len ; i++){
						if(data[item][i]){
							users.push(cdiv.render('user',data[item][i]));
						}
					}
				}
				$('#conv-lists-' + item).html(users.join(''));
			}
		}
	},
	/**
	 * 1.初始化房间
	 * 2.初始化每个房间的用户列表
	 * 3.初始化每个房间的聊天列表
	 */
	initRooms:function(data){
		console.log("initRooms");
		var rooms = [];//房间列表
		var userlists = [];//用户列表
		var chatlists = [];//聊天列表
		if(data.length){
			var display = 'none';
			for(var i=0; i< data.length;i++){
				if(data[i]){
					//存储所有房间ID
					this.data.rds.push(data[i].roomid);
					data[i].selected = '';
					if(i == 0){ 
						data[i].selected = 'selected';
						this.data.crd = data[i].roomid; //存储第一间房间ID，自动设为默认房间ID
						display = 'block';//第一间房的用户列表和聊天记录公开
					} 
					//初始化每个房间的用户列表
					userlists.push(cdiv.userlists(data[i].roomid,display));
					//初始化每个房间的聊天列表
					chatlists.push(cdiv.chatlists(data[i].roomid,display));
					//创建所有的房间
					rooms.push(cdiv.render('rooms',data[i]));
					display = 'none';
				}
			}
			$('.main-menus').html(rooms.join(''));
			$("#user-lists").html(userlists.join(''));
			$("#chat-lists").html(chatlists.join(''));
		}
	},
	loginDiv : function(data){
		console.log("loginDiv");
		/*设置当前房间*/
		this.data.crd = data.roomid;
		/*显示头像*/
		$('.profile').html(cdiv.render('my',data));
		$('#loginbox').fadeOut(function(){
			$('.input-area').fadeIn();
			$('.action-area').fadeIn();
			$('.input-area').focus();
		});
	},
	changeRoom : function(obj){
		console.log("changeRoom");
		//未登录
		if(!this.data.login) {
			this.shake();
			chat.displayError('chatErrorMessage_logout',"未登录用户不能查看房间哦～",1);
			return false;
		}
		var roomid = $(obj).attr("roomid");
		var userObj = $("#conv-lists-"+roomid).find('#user-'+this.data.fd);
		if(userObj.length > 0){
			return;
		}
		
		$("#main-menus").children().removeClass("selected");
		$("#user-lists").children().css("display","none");

		$("#chat-lists").children().css("display","none");
		$("#conv-lists-" + roomid).css('display',"block");
		$(obj).addClass('selected');
		$("#chatLineHolder-" + roomid).css('display',"block");
		var oldroomid = this.data.crd;
		//设置当前房间
		this.data.crd = roomid;
		//用户切换房间
		this.data.type = 3;//改变房间
		var json = {"type": chat.data.type,"token": chat.data.token,"avatar": chat.data.avatar,"oldroomid":oldroomid,"roomid":this.data.crd};
		chat.wsSend(JSON.stringify(json));
		
	},
	
	// The addChatLine method ads a chat entry to the page
	
	addChatLine : function(t,params,roomid){
		console.log("addChatLine");
		var markup = cdiv.render(t,params);
		$("#chatLineHolder-"+roomid).append(markup);
		this.scrollDiv('chat-lists');
	},
	addUserLine : function(t,params){
		console.log("addUserLine");
		var markup = cdiv.render(t,params);
		$('#conv-lists-'+params.roomid).append(markup);
	},
	removeUser : function (t,params){ //type 1=换房切换，0=退出
		console.log("removeUser");
		$("#user-"+params.fd).fadeOut(function(){
			$(this).remove();
			$("#chatLineHolder").append(cdiv.render(t,params));
		});
	},
	changeUser : function( data ){
		console.log("changeUser");
		$("#conv-lists-"+data.oldroomid).find('#user-' + data.fd).fadeOut(function(){
			$(this).remove();
			//chat.addChatLine('logout',data,data.oldroomid);
		});
	},
	scrollDiv:function(t){
		console.log("scrollDiv");
		var mai=document.getElementById(t);
		mai.scrollTop = mai.scrollHeight+100;//通过设置滚动高度
	},
	remind : function(obj){
		console.log("remind");
		var msg = $("#chattext").val();
		$("#chattext").val(msg + "@" + $(obj).attr('uname') + "　");
	},
	
	// This method displays an error message on the top of the page:
	displayError : function(divID,msg,f){
		console.log("displayError");
		var elem = $('<div>',{
			id		: divID,
			html	: msg
		});
		
		elem.click(function(){
			$(this).fadeOut(function(){
				$(this).remove();
			});
		});
		if(f){
			setTimeout(function(){
				elem.click();
			},5000);	
		}
		elem.hide().appendTo('body').slideDown();
	},
	chatAudio : function(){
		console.log("chatAudio");
		if ( $("#chatAudio").length <= 0 ) {
			$('<audio id="chatAudio"><source src="./static/voices/notify.ogg" type="audio/ogg"><source src="./static/voices/notify.mp3" type="audio/mpeg"><source src="./static/voices/notify.wav" type="audio/wav"></audio>').appendTo('body');
		} 
		$('#chatAudio')[0].play();
	},
	shake : function(){
		console.log("shake");
		$("#layout-main").attr("class", "shake_p");
		var shake = setInterval(function(){  
			$("#layout-main").attr("class", "");
			clearInterval(shake);
		},200);
	},
	off : function(){
		console.log("off");
		document.onkeydown = function (event){
			console.log(event);
			/*
			if ( event.keyCode == 116){	//屏蔽F5
				event.keyCode = 0;
				event.cancelBubble = true;
				return false;
			} */
		}
	},
	copyright:function(){	//版权信息
		console.log("联系我们：e_dao@qq.com");
	},
	print:function(flag,obj){
		console.log("print");
		console.log('----' + flag + ' start-------');
		console.log(obj);
		console.log('----' + flag + ' end-------');
	}
}