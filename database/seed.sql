-- ============================================================
-- EXRE B2B2C 平台种子数据 (测试阶段)
-- 超管: admin@exre.local / Admin@2026
-- 测试用户: test@example.com / 123456
-- ============================================================
INSERT INTO app_users (email, username, password_hash, platform_role, ban, created_time, updated_time)
VALUES
  ('admin@exre.local', '超级管理员', '$2y$12$zcfDqaqMan8DpWIfqtwArepgTSrJGw55tYpVNyVztSEkktc/wY5oW', 'superadmin', 0, to_char(now(), 'YYYY-MM-DD HH24:MI:SS'), to_char(now(), 'YYYY-MM-DD HH24:MI:SS')),
  ('test@example.com', '测试用户', '$2y$12$Sh9Y/WRerHqvsFga3ZMyYOyMGIocq.CzSGA8Uk9cIPUc.CEJoLqnO', '', 0, to_char(now(), 'YYYY-MM-DD HH24:MI:SS'), to_char(now(), 'YYYY-MM-DD HH24:MI:SS'));
