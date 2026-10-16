<?php
// 运行时目录兜底：即使被当作入口直接访问，也不返回任何内容
http_response_code(404);
exit;
