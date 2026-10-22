<?php

namespace support\LocalDevelopment;

/** 启动器自己构造的安全诊断；外部异常消息可能包含凭据，不直接输出。 */
class RuntimeFailure extends \RuntimeException {}
