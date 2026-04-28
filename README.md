  
# 🐟 Smart Aquaculture System
ระบบบริหารจัดการฟาร์มเพาะเลี้ยงสัตว์น้ำอัจฉริยะ  
พัฒนาด้วย PHP + MySQL พร้อม REST API  
รองรับการแจ้งเตือนอัตโนมัติผ่าน cron job

## 🛠️ Tech Stack
- **Backend:** PHP, REST API
- **Database:** MySQL
- **Infrastructure:** Docker, docker-compose
- **Other:** Cron job สำหรับ automated tasks

## 🔒 Security Considerations
- ใช้ `.env` สำหรับเก็บ credentials  
  (ไม่ hardcode ใน source code)
- แยก admin panel ออกจาก public API
- มี input validation ใน API layer

## 🚀 วิธีติดตั้ง
1. Clone repo
2. Copy `.env.example` เป็น `.env` แล้วกรอกค่า
3. รัน `docker-compose up -d`
4. Import `SmartAqua.session.sql` เข้า MySQL
