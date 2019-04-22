<?php
require ('MyRedis.php');
use Swoole\WebSocket\Server;
use Swoole\Coroutine\Redis;
use Swoole\Coroutine\MySQL;
use MySwoole\MyRedis;
 class socket{

     public function __construct()
     {
         $this->socket=new server('0.0.0.0' ,9502 );
         $this->socket->set(
             [
                 'enable_static_handler' => true,
                 'document_root' => __DIR__."/index",
                 'worker_num' => 4,
                 'task_worker_num' => 4,
             ]
         );
         $this->socket->on('message',[$this, 'onMessage']);
         $this->socket->on('request',[$this,'onRequest']);
         $this->socket->on('open',[$this, 'onOpen']);
         $this->socket->on('task',[$this,'onTask']);
         $this->socket->on('finish',[$this,'onFinish']);
         $this->socket->on('close',[$this,'onClose']);
         $this->socket->start();
     }

     public function onRequest($request, $response){
         if($request->post['user_name'] && $request->post['pass_word']){
             $data['user_name']=$request->post['user_name'];
             $data['pass_word']=$request->post['pass_word'];
             $swoole_mysql = new MySQL();
             $swoole_mysql->connect([
                 'host' => '',
                 'port' => 3306,
                 'user' => '',
                 'password' => '',
                 'database' => '',
             ]);
             $sql='select * from swoole_user WHERE user_name="'. $data['user_name'].'" and pass_word="'.$data['pass_word'].'"';
             $res = $swoole_mysql->query($sql);
             if(!$res){
                 $sql='INSERT INTO swoole_user (user_name, pass_word) VALUES  ("'.$data['user_name'].'", "'.$data['pass_word'].'")';
                 $res = $swoole_mysql->query($sql);
                 $sql='select * from swoole_user WHERE user_name="'. $data['user_name'].'" and pass_word="'.$data['pass_word'].'"';
                 $res = $swoole_mysql->query($sql);
             }
             $swoole_mysql->close();
             $response->end(json_encode(['status'=>1,'msg'=>'获取成功','data'=>$res['0']]));
         }
     }

     public function  onMessage($server,$frame){
           $redis=MyRedis::getinstance();
//           echo $frame->fd;
           $receive=json_decode($frame->data,true);
           if($receive['type']==1){  //调用tash任务进行处理
               $data = [
                 'fd' => $frame->fd,
                 'receive'=>$receive,
                 'type'=>1,
               ];
               $server->task($data);
           }else{  //进行消息推送
               echo $frame->fd;
               $user_name=$redis->get('user_name_'.$frame->fd);
               $content=[
                   'content'=>$receive['content'],
                   'user_name'=>$user_name,
                   'type'=>2,
               ];
               $chat_user=$redis->smembers('swoole_chat');
               foreach ($chat_user as $k){
                   if($k != $frame->fd){
                       $server->push($k,json_encode($content));
                   }
               }
               $content['user_id']=$redis->get('user_id_'.$frame->fd);
               $redis->lpush('chat_list',json_encode($content));
           }
     }


     public function onTask($server, $taskId, $workerId, $data) {
         $woker_data=[];
         $redis=MyRedis::getinstance();
         if($data['type']==1){
             $redis->set('user_id_'.$data['fd'],$data['receive']['user_id']);
             $redis->set('user_name_'.$data['fd'],$data['receive']['user_name']);
             $chat_user=$redis->smembers('swoole_chat');
             $content=$data['receive']['user_name'].'上线了';
             $result=['type'=>1,'content'=>$content];
             foreach ($chat_user as $k=>$v){
                 if($v != $data['fd']){
                     $server->push($v,json_encode($result));
                 }
             }
         }
//         for($i=0;$i<$data['count'];$i++){  //$data['count'] 为10
//             $process=new swoole_process(function ( swoole_process $woker)use($i){
//                 $result=$this->sleep($i);
//                 $woker->write($result);
//             },true);
//             $process->start();
//             $woker_data[]=$process;
//         }
//         foreach ($woker_data as $k){
//             $result=$result.$k->read().PHP_EOL;
//             $k::wait();
//         }
//         $result=$result.date('Ymd H:i:s').PHP_EOL;
//         $server->push($data['fd'],$result);
         return "on task finish"; // 告诉worker
     }

     public function onOpen($server,$req){
         echo $req->fd.PHP_EOL;
         $redis=MyRedis::getinstance();
         $redis->sadd('swoole_chat',$req->fd);//集合里面添加用户的swoole的fd
         $redis=MyRedis::getinstance();
         $result=$redis->lrange('chat_list',0,10);
         $result=array_reverse($result);
         $content=['type'=>3,'content'=>$result];
         $server->push($req->fd,json_encode($content));
     }


     public function onFinish($serv, $taskId, $data) {
//         echo "taskId:{$taskId}\n";
         echo "finish-data-sucess:{$data}\n";
     }

     public function onClose($serv,$fd,$reactorId){
         $redis=MyRedis::getinstance();
         $close=$redis->sismember('swoole_chat',$fd);
         if($close){
             $user_name=$redis->get('user_name_'.$fd);
             if($user_name){
                 $chat_user=$redis->smembers('swoole_chat');
                 $content=$user_name.'下线了';
                 $result=['type'=>1,'content'=>$content];
                 if(count($chat_user)>1){
                     foreach ($chat_user as $k=>$v){
                         echo $v.PHP_EOL;
                         if($v != $fd ){
                             $serv->push($v,json_encode($result));
                         }
                     }
                 }
             }
             $redis->del('user_id_'.$fd);
             $redis->del('user_name_'.$fd);
             $redis->srem('swoole_chat',$fd);
         }

     }


 }

 new socket();