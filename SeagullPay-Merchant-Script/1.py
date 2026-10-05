import requests
import json
import os
import time
import threading
from datetime import datetime
import sys
import random
import string
from http.server import BaseHTTPRequestHandler, HTTPServer
import urllib.parse

CONFIG_FILE = 'config.json'
LOG_FILE = 'app.log'
PROCESSED_TX_FILE = 'processed_tx.json'
FULFILLED_LINKS_FILE = 'fulfilled_links.json'
BASE_URL = 'https://seagull.teft.cn/api.php'

def c_ljust(s, width):
    """用于包含中文的字符串的左对齐，确保表格在CLI中对齐"""
    # 计算字符串的显示宽度：中文算2，英文算1
    length = sum(2 if ord(c) > 127 else 1 for c in s)
    return s + ' ' * max(0, width - length)

class LocalAPIHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        if parsed.path == '/api/create_link':
            query = urllib.parse.parse_qs(parsed.query)
            amount_str = query.get('amount', [''])[0]
            try:
                amount = float(amount_str)
                if amount <= 0:
                    raise ValueError
                res = self.server.cli_instance.create_auto_link_api(amount)
                if res:
                    self.send_response(200)
                    self.send_header('Content-type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'status': 'success', 'data': res}).encode('utf-8'))
                else:
                    self.send_response(500)
                    self.send_header('Content-type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'status': 'error', 'message': 'Failed to create link'}).encode('utf-8'))
            except:
                self.send_response(400)
                self.send_header('Content-type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({'status': 'error', 'message': 'Invalid amount'}).encode('utf-8'))
        else:
            self.send_response(404)
            self.end_headers()
            
    def log_message(self, format, *args):
        pass

class SeagullCLI:
    def __init__(self):
        self.session = requests.Session()
        self.email = ""
        self.password = ""
        self.is_logged_in = False
        self.relogin_lock = threading.Lock()
        self.last_login_time = 0
        
        self.balance = "--"
        self.transactions = []
        self.links = []
        
        self.logs = [] # 保持最后 8 条记录用于屏幕展示
        self.print_lock = threading.Lock()
        self.fulfillment_lock = threading.Lock()
        self.last_render_state = ""
        self.pause_dashboard = False
        
        self.processed_tx = set()
        self.fulfilled_links = set()
        self.is_first_fetch = True
        self.load_processed_tx()
        self.load_fulfilled_links()

    def log(self, message):
        """记录日志到文件和内存（用于屏幕打印）"""
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        log_msg = f"[{timestamp}] {message}"
        
        # 写入文件
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(log_msg + '\n')
            
        # 保存到内存中展示
        self.logs.append(log_msg)
        if len(self.logs) > 8:
            self.logs.pop(0)
            
        # 当有新日志产生时，强制重新渲染UI
        self.render_dashboard(force=True)

    def load_config(self):
        """读取本地配置文件"""
        self.webhook_url = ""
        self.api_port = 54321
        if os.path.exists(CONFIG_FILE):
            try:
                with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
                    config = json.load(f)
                    self.email = config.get('email', '')
                    self.password = config.get('password', '')
                    self.webhook_url = config.get('webhook_url', '')
                    self.api_port = config.get('api_port', 54321)
            except Exception as e:
                self.log(f"加载配置失败: {e}")

    def save_config(self):
        """保存配置到本地"""
        try:
            with open(CONFIG_FILE, 'w', encoding='utf-8') as f:
                json.dump({
                    'email': self.email, 
                    'password': self.password,
                    'webhook_url': getattr(self, 'webhook_url', ''),
                    'api_port': getattr(self, 'api_port', 54321)
                }, f)
        except Exception as e:
            self.log(f"保存配置失败: {e}")

    def run(self):
        """入口方法"""
        self.load_config()
        
        # 在 Windows 上启用 ANSI 转义序列支持 (为了无闪烁清屏)
        if os.name == 'nt':
            os.system('')
        
        # 初始清屏
        sys.stdout.write("\033[H\033[J")
        sys.stdout.flush()
        
        print("="*80)
        print(" 欢迎使用 众鸥支付 (Seagull Pay) CLI 控制台")
        print("="*80)
        
        if not self.email or not self.password:
            self.email = input("请输入登录邮箱: ").strip()
            self.password = input("请输入登录密码: ").strip()
            self.webhook_url = input("请输入 Webhook 回调 URL (如不需要请直接回车): ").strip()
            self.save_config()
            
        self.log(f"开始使用邮箱 {self.email} 登录...")
        if not self.do_login(self.email, self.password, quiet=False):
            print("登录失败，程序即将退出。")
            return
            
        self.log("系统启动，开始后台轮询...")
        
        # 启动本地 API 服务
        def _start_api_server():
            try:
                server = HTTPServer(('127.0.0.1', self.api_port), LocalAPIHandler)
                server.cli_instance = self
                self.log(f"本地 API 服务已启动: http://127.0.0.1:{self.api_port}/api/create_link?amount=10")
                server.serve_forever()
            except Exception as e:
                self.log(f"本地 API 服务启动失败: {e}")
        threading.Thread(target=_start_api_server, daemon=True).start()
        
        self.pause_dashboard = False
        polling_th = threading.Thread(target=self.poll_data, daemon=True)
        polling_th.start()
        
        def _input_listener():
            try:
                while True:
                    cmd = input().strip().lower()
                    if cmd == 'c':
                        self.pause_dashboard = True
                        sys.stdout.write("\n\n>>> 进入创建收款链接模式 <<<\n")
                        sys.stdout.flush()
                        
                        amt_str = input("请输入收款金额 (输入 q 取消): ").strip()
                        if amt_str.lower() != 'q':
                            try:
                                amt = float(amt_str)
                                if amt <= 0:
                                    print("金额必须大于0！")
                                else:
                                    self.create_auto_link(amt)
                            except ValueError:
                                print("金额格式错误！")
                                
                        print(">>> 恢复后台监控 (2秒内刷新) <<<")
                        time.sleep(1)
                        self.last_render_state = ""
                        self.pause_dashboard = False
            except (EOFError, KeyboardInterrupt):
                pass
                
        input_th = threading.Thread(target=_input_listener, daemon=True)
        input_th.start()
        
        try:
            while True:
                time.sleep(1)
        except KeyboardInterrupt:
            self.pause_dashboard = True
            sys.stdout.write("\033[H\033[J")
            sys.stdout.flush()
            print("\n程序已安全退出。")

    def _check_and_relogin(self):
        """线程安全的重新登录机制"""
        with self.relogin_lock:
            if time.time() - self.last_login_time < 5:
                return self.is_logged_in
            self.log("检测到会话过期，正在自动重新登录...")
            return self.do_login(self.email, self.password, quiet=True)

    def do_login(self, email, password, quiet=False):
        """执行登录 API 请求"""
        try:
            resp = self.session.post(BASE_URL, params={'action': 'login'}, data={'email': email, 'password': password}, timeout=10)
            res = resp.json()
            if res.get('status') == 'success':
                self.is_logged_in = True
                self.last_login_time = time.time()
                self.save_config()
                self.log("登录成功。")
                return True
            else:
                self.is_logged_in = False
                msg = res.get('message', '未知错误')
                self.log(f"登录失败: {msg}")
                return False
        except Exception as e:
            self.log(f"登录异常: {str(e)}")
            self.is_logged_in = False
            return False

    def api_call(self, action, method='GET', data=None, params=None, quiet_log=False, timeout=5):
        """通用 API 请求封装，包含会话失效自动重试机制"""
        if not self.is_logged_in:
            return None
            
        if params is None:
            params = {}
        params['action'] = action
        
        try:
            if method == 'GET':
                resp = self.session.get(BASE_URL, params=params, timeout=timeout)
            else:
                resp = self.session.post(BASE_URL, params=params, data=data, timeout=timeout)
                
            if resp.status_code != 200:
                if not quiet_log:
                    self.log(f"接口 {action} 请求失败，状态码: {resp.status_code}")
                return None
                
            res_json = resp.json()
            
            if res_json.get('status') == 'error':
                msg = str(res_json.get('message', ''))
                # 检查是否因会话失效导致的错误
                if any(k in msg.lower() for k in ['登录', 'login', 'session', '未登录', '失效', 'unauthorized']):
                    if self._check_and_relogin():
                        # 重登录成功后重发请求
                        if method == 'GET':
                            resp = self.session.get(BASE_URL, params=params, timeout=timeout)
                        else:
                            resp = self.session.post(BASE_URL, params=params, data=data, timeout=timeout)
                        res_json = resp.json()
            
            if not quiet_log and res_json.get('status') != 'success':
                self.log(f"接口操作异常 {action}: {res_json.get('message', '')}")
                
            return res_json
        except Exception as e:
            if not quiet_log:
                self.log(f"接口请求异常 ({action}): {str(e)}")
            return None

    def poll_data(self):
        """2秒轮询任务"""
        while True:
            if self.is_logged_in:
                self.update_balance()
                self.update_payment_links()
                self.update_transactions()
                self.render_dashboard()
            time.sleep(2)

    def update_balance(self):
        res = self.api_call('get_balance', method='GET', quiet_log=True)
        if res and res.get('status') == 'success':
            self.balance = res.get('balance', '0.00')

    def update_transactions(self):
        res = self.api_call('get_transactions', method='GET', params={'page': 1}, quiet_log=True)
        if res and res.get('status') == 'success':
            tx_list = res.get('data', [])
            if not isinstance(tx_list, list):
                tx_list = res.get('transactions', [])
                if not isinstance(tx_list, list):
                    tx_list = []
            self.transactions = tx_list
            self.check_auto_refund(tx_list)

    def update_payment_links(self):
        res = self.api_call('get_payment_links', method='GET', params={'page': 1}, quiet_log=True)
        if res and res.get('status') == 'success':
            links = res.get('data', [])
            if not isinstance(links, list):
                links = res.get('links', [])
                if not isinstance(links, list):
                    links = []
            self.links = links

    def load_processed_tx(self):
        if os.path.exists(PROCESSED_TX_FILE):
            try:
                with open(PROCESSED_TX_FILE, 'r', encoding='utf-8') as f:
                    self.processed_tx = set(json.load(f))
            except Exception as e:
                self.log(f"加载处理记录失败: {e}")
                self.processed_tx = set()
        else:
            self.processed_tx = set()

    def save_processed_tx(self):
        try:
            with open(PROCESSED_TX_FILE, 'w', encoding='utf-8') as f:
                json.dump(list(self.processed_tx), f)
        except Exception as e:
            self.log(f"保存处理记录失败: {e}")

    def load_fulfilled_links(self):
        if os.path.exists(FULFILLED_LINKS_FILE):
            try:
                with open(FULFILLED_LINKS_FILE, 'r', encoding='utf-8') as f:
                    self.fulfilled_links = set(json.load(f))
            except:
                self.fulfilled_links = set()
        else:
            self.fulfilled_links = set()

    def save_fulfilled_links(self):
        try:
            with open(FULFILLED_LINKS_FILE, 'w', encoding='utf-8') as f:
                json.dump(list(self.fulfilled_links), f)
        except Exception as e:
            self.log(f"保存核销记录失败: {e}")

    def find_payment_link_by_note(self, note):
        """根据备注查找收款链接（包括所有分页）"""
        for link in self.links:
            if str(link.get('note', '')) == note:
                return link
        for page in range(1, 6):
            res = self.api_call('get_payment_links', method='GET', params={'page': page}, quiet_log=True)
            if res and res.get('status') == 'success':
                page_links = res.get('data', [])
                if not isinstance(page_links, list):
                    page_links = res.get('links', [])
                    if not isinstance(page_links, list):
                        page_links = []
                for link in page_links:
                    if str(link.get('note', '')) == note:
                        return link
                if len(page_links) < 5:
                    break
        return None

    def create_auto_link_api(self, amount):
        while True:
            note = ''.join(random.choices(string.ascii_uppercase + string.digits, k=16))
            if not self.find_payment_link_by_note(note):
                break
                
        data = {
            'amount': amount,
            'note': note
        }
        res = self.api_call('create_payment_link', method='POST', data=data, quiet_log=False, timeout=10)
        if res and res.get('status') == 'success':
            code = res.get('code', res.get('data', ''))
            self.log(f"成功创建收款链接: 提取码 {code}, 金额 ￥{amount}, 订单号 {note}")
            return {'note': note, 'code': code, 'amount': amount}
        else:
            msg = res.get('message', '未知') if res else '请求失败'
            self.log(f"创建收款链接失败: ￥{amount} ({msg})")
            return None

    def create_auto_link(self, amount):
        print("正在生成唯一的 16 位订单号并提交 API...")
        res = self.create_auto_link_api(amount)
        if res:
            print(f"生成的订单号 (备注): {res['note']}")
            print(f"创建成功！提取码: {res['code']}")
        else:
            print(f"创建失败，请查看日志。")

    def send_webhook(self, note, amount, sender):
        if not getattr(self, 'webhook_url', ''):
            return
            
        def _webhook_task():
            data = {
                'note': note,
                'amount': amount,
                'sender': sender
            }
            while True:
                try:
                    resp = requests.post(self.webhook_url, json=data, timeout=10)
                    if resp.text.strip().lower() == 'ok':
                        self.log(f"Webhook 回调成功: 订单 {note}")
                        break
                    else:
                        self.log(f"Webhook 未返回ok，10秒后重试: 订单 {note}")
                except Exception as e:
                    self.log(f"Webhook 请求异常，10秒后重试: {e}")
                time.sleep(10)
                
        threading.Thread(target=_webhook_task, daemon=True).start()

    def check_auto_refund(self, tx_list):
        if self.is_first_fetch:
            # 刚启动时，第一次获取到的历史订单一律忽略，全部标记为已处理
            for tx in tx_list:
                t_id = str(tx.get('id', ''))
                note = str(tx.get('note', ''))
                if t_id:
                    self.processed_tx.add(t_id)
                if len(note) == 16:
                    matched = self.find_payment_link_by_note(note)
                    if matched and matched.get('status') == 'completed':
                        with self.fulfillment_lock:
                            self.fulfilled_links.add(note)
            self.save_processed_tx()
            self.save_fulfilled_links()
            self.is_first_fetch = False
            return
            
        for tx in tx_list:
            t_id = str(tx.get('id', ''))
            receiver = str(tx.get('receiver_email', ''))
            sender = str(tx.get('sender_email', ''))
            t_type = str(tx.get('type', ''))
            amount = str(tx.get('amount', ''))
            note = str(tx.get('note', ''))
            
            # 检测对方转给我的订单（排除给自己转账以及系统充值等）
            if receiver == self.email and sender and sender != self.email and sender != 'system':

                if t_id and t_id not in self.processed_tx:
                    self.processed_tx.add(t_id)
                    self.save_processed_tx()
                    
                    should_refund = True
                    reason = "非预期转账"
                    
                    matched_link = self.find_payment_link_by_note(note) if len(note) == 16 else None
                    
                    if matched_link:
                        link_status = str(matched_link.get('status', ''))
                        link_amount = str(matched_link.get('amount', ''))
                        
                        try:
                            # 容错处理浮点数比较
                            is_amt_match = float(link_amount) == float(amount)
                        except:
                            is_amt_match = (link_amount == amount)
                            
                        if link_status == 'completed' and is_amt_match:
                            with self.fulfillment_lock:
                                if note in self.fulfilled_links:
                                    should_refund = True
                                    reason = "订单重复付款 (该链接已被其他转账核销)"
                                else:
                                    should_refund = False
                                    self.fulfilled_links.add(note)
                                    self.save_fulfilled_links()
                            if not should_refund:
                                self.log(f"收款链接订单正常: 来自 {sender} 的 ￥{amount}，订单号: {note}，已核销，无需退款。")
                                self.send_webhook(note, amount, sender)
                        else:
                            if not is_amt_match:
                                reason = f"金额不匹配 (转账￥{amount} != 链接￥{link_amount})"
                            elif link_status != 'completed':
                                reason = f"收款链接状态未完成 ({link_status})"
                    else:
                        if not note:
                            reason = "无备注"
                        elif len(note) == 16:
                            reason = f"备注无匹配链接 ({note})"
                        else:
                            reason = f"备注非16位订单号 ({note})"
                            
                    if should_refund:
                        if "未完成" in reason:
                            self.log(f"注意: 发现收款链接 {note} 的匹配转账，但本地状态仍为未完成。进入10秒等待期以防数据延迟...")
                            threading.Thread(target=self.delayed_refund_check, args=(sender, amount, note, t_id)).start()
                        else:
                            self.log(f"检测到违规或异常入账: 来自 {sender} 的 ￥{amount}，原因: {reason}。准备自动退款...")
                            threading.Thread(target=self.do_refund, args=(sender, amount, t_id)).start()

    def do_refund(self, receiver_email, amount, t_id):
        data = {
            'receiver_email': receiver_email,
            'amount': amount,
            'note': '非活动订单自动退款',
            'password': self.password
        }
        max_retries = 3
        for attempt in range(1, max_retries + 1):
            res = self.api_call('transfer', method='POST', data=data, quiet_log=False, timeout=60)
            if res and res.get('status') == 'success':
                self.log(f"自动退款成功: 已将 ￥{amount} 退回给 {receiver_email} (原交易ID: {t_id})")
                return
            else:
                msg = res.get('message', '未知') if res else '请求超时或网络异常'
                if attempt < max_retries:
                    self.log(f"自动退款失败 (第{attempt}次): 退回 ￥{amount} 给 {receiver_email} 失败 ({msg})。10秒后重试...")
                    time.sleep(10)
                else:
                    self.log(f"自动退款最终失败: 退回 ￥{amount} 给 {receiver_email} 失败 ({msg})，已放弃重试。")

    def delayed_refund_check(self, sender, amount, note, t_id):
        # 等待10秒，让后端有充足时间完成链接状态的同步更新
        time.sleep(10)
        
        # 强制去 API 重新查询最新的链接状态
        matched_link = None
        for page in range(1, 6):
            res = self.api_call('get_payment_links', method='GET', params={'page': page}, quiet_log=True)
            if res and res.get('status') == 'success':
                page_links = res.get('data', [])
                if not isinstance(page_links, list):
                    page_links = res.get('links', [])
                    if not isinstance(page_links, list):
                        page_links = []
                for link in page_links:
                    if str(link.get('note', '')) == note:
                        matched_link = link
                        break
                if matched_link or len(page_links) < 5:
                    break
                    
        if matched_link:
            link_status = str(matched_link.get('status', ''))
            if link_status == 'completed':
                is_fulfilled_now = False
                with self.fulfillment_lock:
                    if note in self.fulfilled_links:
                        reason = "二次复核失败: 订单虽完成，但已被更早的转账核销"
                    else:
                        self.fulfilled_links.add(note)
                        self.save_fulfilled_links()
                        is_fulfilled_now = True
                if is_fulfilled_now:
                    self.log(f"二次复核通过: 收款链接订单 {note} 状态已更新为 completed，确认是有效付款，已核销并取消退款。")
                    self.send_webhook(note, amount, sender)
                    return
            else:
                reason = f"二次复核失败: 收款链接状态仍为 ({link_status})"
        else:
            reason = "二次复核失败: 链接已不存在"
            
        self.log(f"二次复核未通过: 来自 {sender} 的 ￥{amount}，原因: {reason}。准备执行退款...")
        self.do_refund(sender, amount, t_id)

    def render_dashboard(self, force=False):
        """使用 ANSI 转移序列防闪烁刷新控制台界面"""
        if not self.is_logged_in or getattr(self, 'pause_dashboard', False):
            return
            
        with self.print_lock:
            lines = []
            lines.append("=" * 80)
            lines.append(f" 众鸥支付 (Seagull Pay) CLI 控制台  -  当前登录: {self.email}  -  余额: ￥{self.balance}")
            lines.append("=" * 80)
            
            # 1. 收款链接状态
            lines.append("\n[ 收款链接状态 ]")
            header = f"{c_ljust('ID', 5)} | {c_ljust('支付代码', 16)} | {c_ljust('金额', 10)} | {c_ljust('状态', 12)} | {c_ljust('创建时间', 19)}"
            lines.append(header)
            lines.append("-" * 80)
            if not self.links:
                lines.append("暂无收款链接记录")
            else:
                for link in self.links[:5]:
                    l_id = str(link.get('id', ''))
                    code = str(link.get('code', ''))
                    amt = str(link.get('amount', ''))
                    status = str(link.get('status', ''))
                    created = str(link.get('created_at', ''))
                    row = f"{c_ljust(l_id, 5)} | {c_ljust(code, 16)} | {c_ljust(amt, 10)} | {c_ljust(status, 12)} | {c_ljust(created, 19)}"
                    lines.append(row)
            
            # 2. 最新交易记录
            lines.append("\n[ 最新交易记录 ]")
            t_header = f"{c_ljust('ID', 5)} | {c_ljust('对方账户', 22)} | {c_ljust('金额', 10)} | {c_ljust('类型', 10)} | {c_ljust('时间', 19)}"
            lines.append(t_header)
            lines.append("-" * 80)
            if not self.transactions:
                lines.append("暂无交易记录")
            else:
                for tx in self.transactions[:5]:
                    t_id = str(tx.get('id', ''))
                    amount = str(tx.get('amount', ''))
                    t_type = str(tx.get('type', ''))
                    created = str(tx.get('created_at', ''))
                    
                    sender = str(tx.get('sender_email', ''))
                    receiver = str(tx.get('receiver_email', ''))
                    other_account = sender if self.email != sender else receiver
                    
                    # 截断过长的邮箱
                    if len(other_account) > 22:
                        other_account = other_account[:19] + "..."
                        
                    t_row = f"{c_ljust(t_id, 5)} | {c_ljust(other_account, 22)} | {c_ljust(amount, 10)} | {c_ljust(t_type, 10)} | {c_ljust(created, 19)}"
                    lines.append(t_row)
                    
            # 3. 日志面板
            lines.append("\n[ 最近操作日志 (完整日志自动保存至 app.log) ]")
            lines.append("-" * 80)
            for log in self.logs:
                lines.append(log)
            
            output = "\n".join(lines)
            
            # 只有当内容发生变化，或者强制要求更新时才重新绘制
            if force or output != self.last_render_state:
                # \033[H 移动光标到行首， \033[J 清除屏幕下方内容
                sys.stdout.write("\033[H\033[J")
                sys.stdout.write(output + "\n\n(输入 c 并回车创建收款链接，按 Ctrl+C 退出程序，数据每2秒自动刷新)\n")
                sys.stdout.flush()
                self.last_render_state = output

if __name__ == '__main__':
    cli = SeagullCLI()
    cli.run()
