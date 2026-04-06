-- ============================================================
--  GWME Blog — MySQL Database Schema
--  Run this file once to set up your database
-- ============================================================

CREATE DATABASE IF NOT EXISTS gwme_blog
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gwme_blog;

-- ── POSTS TABLE ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS posts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(255)  NOT NULL,
  slug          VARCHAR(255)  NOT NULL UNIQUE,
  excerpt       TEXT          NOT NULL,
  content       LONGTEXT      NOT NULL,
  author        VARCHAR(100)  NOT NULL DEFAULT 'Dr. Siya Seth',
  category      ENUM(
                  'Tendering','Compliance','Strategy',
                  'GeM Portal','Case Study','News & Updates'
                ) NOT NULL DEFAULT 'Tendering',
  emoji         VARCHAR(10)   NOT NULL DEFAULT '📄',
  card_color    VARCHAR(20)   NOT NULL DEFAULT '#0A1628',
  published     TINYINT(1)    NOT NULL DEFAULT 0,
  read_time     TINYINT       NOT NULL DEFAULT 5,

  -- SEO fields
  seo_title     VARCHAR(70)   DEFAULT NULL,
  seo_description VARCHAR(165) DEFAULT NULL,
  seo_keywords  TEXT          DEFAULT NULL,   -- comma-separated
  seo_canonical VARCHAR(500)  DEFAULT NULL,
  og_title      VARCHAR(255)  DEFAULT NULL,
  og_description VARCHAR(300) DEFAULT NULL,
  og_image      VARCHAR(500)  DEFAULT NULL,
  schema_enabled TINYINT(1)   NOT NULL DEFAULT 1,
  faq_schema    TINYINT(1)    NOT NULL DEFAULT 0,

  -- Timestamps
  publish_date  DATE          DEFAULT NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_published  (published),
  INDEX idx_category   (category),
  INDEX idx_publish_date (publish_date),
  INDEX idx_slug       (slug)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


-- ── ADMIN SESSIONS TABLE ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_sessions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token       VARCHAR(128) NOT NULL UNIQUE,
  username    VARCHAR(64)  NOT NULL,
  ip_address  VARCHAR(45)  DEFAULT NULL,
  user_agent  VARCHAR(300) DEFAULT NULL,
  expires_at  TIMESTAMP    NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_token      (token),
  INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── LOGIN ATTEMPTS TABLE (brute-force protection) ────────────
CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip (ip_address),
  INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── SAMPLE DATA ──────────────────────────────────────────────
INSERT INTO posts
  (title, slug, excerpt, content, author, category, emoji, card_color,
   published, read_time, seo_title, seo_description, seo_keywords, publish_date)
VALUES
(
  'How to Win Your First ₹1 Crore Government Tender',
  'win-first-crore-government-tender',
  'A step-by-step breakdown of the exact process Dr. Siya Seth used to help 1,500+ MSMEs land their first major government contract without prior experience.',
  'Winning your first government tender feels impossible — until you understand the system.\n\nAfter working with over 1,500 MSMEs across 18 states, I have identified the exact pattern that separates businesses that consistently win contracts from those that keep losing on price.\n\n### The Biggest Mistake MSMEs Make\n\nMost business owners approach government tenders like they approach private clients — leading with price. This is wrong. Government procurement officers are not just buying cheapness; they are buying reliability, compliance, and documentation strength.\n\n<div class="highlight">💡 "A tender bid is not a price quote. It is a comprehensive proof of your business reliability, capability, and compliance." — Dr. Siya Seth</div>\n\n### Step 1: Build Your Eligibility Stack\n\nBefore you touch a single tender form, you need a valid MSME/Udyam Certificate, PAN and GST Registration, relevant experience certificates, a business bank account with 12 months of statements, and EMD capacity.\n\n### Step 2: Choose the Right Tender\n\nDo not bid on everything. Use CPPP, GeM, and state portals to find tenders matching your exact niche. A ₹50 lakh tender you qualify for beats a ₹5 crore tender you do not.\n\n### Step 3: Documentation is Your Competitive Moat\n\nOrganize documents into Eligibility, Technical, and Financial folders. Your technical bid must tell a story.\n\n### Step 4: Price Strategically\n\nUse the BOQ provided in the tender. Price each line item at market rate, then apply a 5–8% buffer. Never bid below cost.',
  'Dr. Siya Seth', 'Tendering', '🏆', '#0A1628',
  1, 8,
  'How to Win Your First ₹1 Crore Government Tender | GWME India',
  'Learn the exact step-by-step system to win your first crore government contract. Proven by 1,500+ MSMEs across India.',
  'government tender India,win government contract,MSME tender tips,first crore tender,Dr Siya Seth GWME',
  '2025-03-15'
),
(
  'GeM Portal: Complete Registration Guide for 2025',
  'gem-portal-registration-guide-2025',
  'Everything you need to register on the Government e-Marketplace — from vendor assessment to your first order — in under 7 days.',
  'The Government e-Marketplace (GeM) is India largest B2G platform with over ₹2 lakh crore in annual procurement.\n\n### What is GeM?\n\nGeM is a fully online portal where government departments purchase goods and services directly from registered sellers.\n\n<div class="highlight">📊 GeM had over ₹4 lakh crore in GMV in FY2024-25, making it the fastest-growing B2G marketplace in the world.</div>\n\n### Step-by-Step Registration\n\nVisit gem.gov.in and click Sign Up as Seller. Enter your Aadhaar-linked mobile for OTP. Fill in PAN, GSTIN, and bank details. Complete Vendor Assessment. Then list your first product or service.\n\n### Pro Tips\n\nList products with maximum 5 high-quality images. Price 3–5% below market to appear in top results. Enable Direct Purchase for items below ₹25,000.',
  'GWME Team', 'GeM Portal', '🖥️', '#0F2520',
  1, 6,
  'GeM Portal Registration Guide 2025 — Step by Step | GWME India',
  'Complete step-by-step guide to register on GeM Portal in 2025. Covers vendor assessment, product listing, and getting your first government order.',
  'GeM portal registration 2025,government e-marketplace,GeM vendor registration,GeM seller guide India',
  '2025-03-08'
),
(
  'JV Strategy: How to Qualify for ₹10 Cr+ Tenders',
  'jv-strategy-qualify-10-crore-tenders',
  'Joint ventures are the fastest way to qualify for larger tenders. Here is how to structure a winning JV agreement that protects your interests.',
  'Most MSMEs disqualify themselves from high-value tenders before they even begin. A Joint Venture solves this.\n\n### Why JVs Work\n\nGovernment tender eligibility typically requires minimum annual turnover, minimum 3–5 years of similar work experience, and adequate equipment. If you cannot meet these alone, you partner with a business that complements your gap.\n\n<div class="highlight">🤝 A JV is not just paperwork — it is a strategic alliance where each partner brings something the other lacks.</div>\n\n### Structuring the JV Agreement\n\nYour JV MoU must clearly define Lead Partner, Work Share, Revenue Share, Dispute Resolution, and Exit Terms.\n\n### Finding the Right Partner\n\nLook for partners with complementary licenses, strong financials for EMD support, prior project experience in your target department, and no blacklisting history.',
  'Dr. Siya Seth', 'Strategy', '🤝', '#1A0D28',
  1, 7,
  'Joint Venture Strategy for Government Tenders — GWME India',
  'Learn how to form JV partnerships to qualify for ₹10 crore+ government tenders. Includes JV MoU structure and partner selection.',
  'JV government tender India,joint venture MSME tender,how to qualify big tender,government contract partnership',
  '2025-02-28'
);
