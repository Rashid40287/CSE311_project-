-- ============================================
-- DATABASE: campus_resource_sharing 
-- ============================================

DROP DATABASE IF EXISTS campus_resource_sharing;
CREATE DATABASE campus_resource_sharing;
USE campus_resource_sharing;

-- ============================================
-- STUDENT
-- ============================================
CREATE TABLE student (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    university_email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    department VARCHAR(100) NOT NULL,
    phone VARCHAR(20) UNIQUE,
    registration_date DATE NOT NULL,
    account_status ENUM('active','suspended','inactive') NOT NULL DEFAULT 'active'
);

-- ============================================
-- CATEGORY
-- ============================================
CREATE TABLE category (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    category_description TEXT
);

-- ============================================
-- ITEM
-- ============================================
CREATE TABLE item (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    category_id INT NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    item_description TEXT,
    item_condition ENUM('new','like_new','good','fair','poor') NOT NULL,
    availability_status ENUM('unavailable','available','borrowed') NOT NULL DEFAULT 'unavailable',
    date_listed DATE NOT NULL,
    ai_generated_description_flag TINYINT(1) NOT NULL DEFAULT 0,
    ai_prompt_text TEXT,

    FOREIGN KEY (owner_id) REFERENCES student(student_id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (category_id) REFERENCES category(category_id) ON DELETE RESTRICT ON UPDATE CASCADE,

    CHECK (
        (ai_generated_description_flag = 1 AND ai_prompt_text IS NOT NULL)
        OR
        (ai_generated_description_flag = 0 AND ai_prompt_text IS NULL)
    )
);

-- ============================================
-- ITEM_IMAGE
-- ============================================
CREATE TABLE item_image (
    image_id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    upload_date DATE NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,

    FOREIGN KEY (item_id) REFERENCES item(item_id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- ============================================
-- BORROW_REQUEST
-- ============================================
CREATE TABLE borrow_request (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    borrower_id INT NOT NULL,
    request_date DATE NOT NULL,
    requested_from_date DATE NOT NULL,
    requested_to_date DATE NOT NULL,
    request_message TEXT,
    request_status ENUM('pending','approved','rejected','cancelled') NOT NULL,
    owner_response_date DATE,

    FOREIGN KEY (item_id) REFERENCES item(item_id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (borrower_id) REFERENCES student(student_id) ON DELETE CASCADE ON UPDATE CASCADE,

    CHECK (requested_to_date > requested_from_date)
);

-- ============================================
-- BORROW_TRANSACTION
-- ============================================
CREATE TABLE borrow_transaction (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL UNIQUE,
    borrow_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE,
    transaction_status ENUM('active','returned','overdue') NOT NULL,

    FOREIGN KEY (request_id) REFERENCES borrow_request(request_id) ON DELETE CASCADE ON UPDATE CASCADE,

    CHECK (due_date >= borrow_date),
    CHECK (
        (transaction_status IN ('active','overdue') AND return_date IS NULL)
        OR
        (transaction_status = 'returned' AND return_date IS NOT NULL)
    )
);

-- ============================================
-- REVIEW
-- ============================================
CREATE TABLE review (
    review_id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    reviewee_id INT NOT NULL,
    rating INT NOT NULL,
    comment TEXT,
    review_date DATE NOT NULL,

    FOREIGN KEY (transaction_id) REFERENCES borrow_transaction(transaction_id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES student(student_id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (reviewee_id) REFERENCES student(student_id) ON DELETE CASCADE ON UPDATE CASCADE,

    CHECK (rating BETWEEN 1 AND 5),

    CONSTRAINT uq_review_per_transaction_per_reviewer UNIQUE (transaction_id, reviewer_id)
);

-- ============================================
-- TRIGGERS
-- ============================================
DELIMITER //

-- only one primary image per item (insert)
CREATE TRIGGER enforce_single_primary_image_insert
BEFORE INSERT ON item_image
FOR EACH ROW
BEGIN
    IF NEW.is_primary = TRUE THEN
        IF EXISTS (
            SELECT 1
            FROM item_image
            WHERE item_id = NEW.item_id
              AND is_primary = TRUE
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only one primary image allowed per item';
        END IF;
    END IF;
END;
//

-- only one primary image per item (update)
CREATE TRIGGER enforce_single_primary_image_update
BEFORE UPDATE ON item_image
FOR EACH ROW
BEGIN
    IF NEW.is_primary = TRUE THEN
        IF EXISTS (
            SELECT 1
            FROM item_image
            WHERE item_id = NEW.item_id
              AND is_primary = TRUE
              AND image_id <> OLD.image_id
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only one primary image allowed per item';
        END IF;
    END IF;
END;
//

-- prevent self borrowing (insert)
CREATE TRIGGER prevent_self_borrow_insert
BEFORE INSERT ON borrow_request
FOR EACH ROW
BEGIN
    DECLARE v_owner_id INT;

    SELECT owner_id INTO v_owner_id
    FROM item
    WHERE item_id = NEW.item_id;

    IF NEW.borrower_id = v_owner_id THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Owner cannot borrow their own item';
    END IF;
END;
//

-- prevent self borrowing (update)
CREATE TRIGGER prevent_self_borrow_update
BEFORE UPDATE ON borrow_request
FOR EACH ROW
BEGIN
    DECLARE v_owner_id INT;

    SELECT owner_id INTO v_owner_id
    FROM item
    WHERE item_id = NEW.item_id;

    IF NEW.borrower_id = v_owner_id THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Owner cannot borrow their own item';
    END IF;
END;
//

-- only approved requests can create transaction
CREATE TRIGGER check_request_approved_insert
BEFORE INSERT ON borrow_transaction
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(20);

    SELECT request_status INTO v_status
    FROM borrow_request
    WHERE request_id = NEW.request_id;

    IF v_status <> 'approved' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Only approved requests can create transaction';
    END IF;
END;
//

-- keep transaction update valid
CREATE TRIGGER check_transaction_update
BEFORE UPDATE ON borrow_transaction
FOR EACH ROW
BEGIN
    IF NEW.transaction_status = 'returned' AND NEW.return_date IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Returned transaction must have return_date';
    END IF;

    IF NEW.transaction_status IN ('active','overdue') AND NEW.return_date IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Active or overdue transaction cannot have return_date';
    END IF;
END;
//

-- validate reviews
CREATE TRIGGER review_validation_insert
BEFORE INSERT ON review
FOR EACH ROW
BEGIN
    DECLARE v_borrower INT;
    DECLARE v_owner INT;
    DECLARE v_status VARCHAR(20);

    -- NEW FIX (replaces CHECK constraint)
    IF NEW.reviewer_id = NEW.reviewee_id THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Reviewer and reviewee cannot be the same person';
    END IF;

    SELECT br.borrower_id, i.owner_id, bt.transaction_status
    INTO v_borrower, v_owner, v_status
    FROM borrow_transaction bt
    JOIN borrow_request br ON bt.request_id = br.request_id
    JOIN item i ON br.item_id = i.item_id
    WHERE bt.transaction_id = NEW.transaction_id;

    IF v_status <> 'returned' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Review allowed only after item is returned';
    END IF;

    IF NEW.reviewer_id NOT IN (v_borrower, v_owner)
       OR NEW.reviewee_id NOT IN (v_borrower, v_owner) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid reviewer or reviewee';
    END IF;
END;
//
-- activate item after first image is added
CREATE TRIGGER item_activate_after_image
AFTER INSERT ON item_image
FOR EACH ROW
BEGIN
    UPDATE item
    SET availability_status = 'available'
    WHERE item_id = NEW.item_id
      AND availability_status = 'unavailable';
END;
//

-- mark item as borrowed after transaction
CREATE TRIGGER mark_item_borrowed
AFTER INSERT ON borrow_transaction
FOR EACH ROW
BEGIN
    UPDATE item i
    JOIN borrow_request br ON i.item_id = br.item_id
    SET i.availability_status = 'borrowed'
    WHERE br.request_id = NEW.request_id;
END;
//

-- mark item available again after return
CREATE TRIGGER mark_item_available_after_return
AFTER UPDATE ON borrow_transaction
FOR EACH ROW
BEGIN
    IF NEW.transaction_status = 'returned' AND OLD.transaction_status <> 'returned' THEN
        UPDATE item i
        JOIN borrow_request br ON i.item_id = br.item_id
        SET i.availability_status = 'available'
        WHERE br.request_id = NEW.request_id;
    END IF;
END;
//

DELIMITER ;

-- ============================================
-- SAMPLE DATA
-- ============================================

-- STUDENT
INSERT INTO student (student_id, full_name, university_email, password_hash, department, phone, registration_date, account_status) VALUES
(1,'md abdullah','abdullah@nsu.edu','hash1','ece','01711111111','2025-09-01','active'),
(2,'mostofa morshed','mostofa@nsu.edu','hash2','ece','01722222222','2025-09-01','active'),
(3,'fatima rahman','fatima@nsu.edu','hash3','cse','01733333333','2025-09-05','active'),
(4,'tanvir ahmed','tanvir@nsu.edu','hash4','cse','01744444444','2025-09-10','active'),
(5,'sadia islam','sadia@nsu.edu','hash5','bba','01755555555','2025-09-12','active'),
(6,'rafiq uddin','rafiq@nsu.edu','hash6','ece','01766666666','2025-09-15','active');

-- CATEGORY
INSERT INTO category (category_id, category_name, category_description) VALUES
(1,'textbooks','academic books'),
(2,'electronics','devices'),
(3,'stationery','study materials'),
(4,'accessories','misc items');

-- ITEM
INSERT INTO item (item_id, owner_id, category_id, item_name, item_description, item_condition, availability_status, date_listed, ai_generated_description_flag, ai_prompt_text) VALUES
(1,1,1,'db book','database systems book','good','unavailable','2025-10-01',0,NULL),
(2,2,2,'calculator','scientific calculator','good','unavailable','2025-10-05',0,NULL),
(3,3,1,'math book','engineering math','like_new','unavailable','2025-10-07',0,NULL),
(4,4,2,'laptop','used laptop','fair','unavailable','2025-10-08',1,'generate laptop description'),
(5,5,3,'notebook','class notebook','new','unavailable','2025-10-09',0,NULL),
(6,6,4,'bag','travel bag','good','unavailable','2025-10-10',0,NULL);

-- ITEM_IMAGE
INSERT INTO item_image (image_id, item_id, image_path, upload_date, is_primary) VALUES
(1,1,'img1.jpg','2025-10-01',TRUE),
(2,2,'img2.jpg','2025-10-05',TRUE),
(3,3,'img3.jpg','2025-10-07',TRUE),
(4,4,'img4.jpg','2025-10-08',TRUE),
(5,5,'img5.jpg','2025-10-09',TRUE),
(6,6,'img6.jpg','2025-10-10',TRUE),
(7,1,'img1_extra.jpg','2025-10-02',FALSE),
(8,2,'img2_extra.jpg','2025-10-06',FALSE);

-- BORROW_REQUEST
INSERT INTO borrow_request (request_id, item_id, borrower_id, request_date, requested_from_date, requested_to_date, request_message, request_status, owner_response_date) VALUES
(1,1,2,'2025-10-02','2025-10-03','2025-10-10','need for study','approved','2025-10-02'),
(2,2,3,'2025-10-06','2025-10-07','2025-10-15','urgent use','approved','2025-10-06'),
(3,3,4,'2025-10-08','2025-10-09','2025-10-20','assignment','pending',NULL),
(4,4,5,'2025-10-09','2025-10-10','2025-10-25','project work','approved','2025-10-09'),
(5,5,6,'2025-10-10','2025-10-11','2025-10-18','notes needed','rejected','2025-10-10');

-- BORROW_TRANSACTION
INSERT INTO borrow_transaction (transaction_id, request_id, borrow_date, due_date, return_date, transaction_status) VALUES
(1,1,'2025-10-03','2025-10-10','2025-10-09','returned'),
(2,2,'2025-10-07','2025-10-15',NULL,'active'),
(3,4,'2025-10-10','2025-10-25',NULL,'active');

-- REVIEW
-- only transaction 1 is returned, so reviews are allowed only for transaction 1
INSERT INTO review (review_id, transaction_id, reviewer_id, reviewee_id, rating, comment, review_date) VALUES
(1,1,2,1,5,'great experience','2025-10-10'),
(2,1,1,2,4,'good borrower','2025-10-10');
