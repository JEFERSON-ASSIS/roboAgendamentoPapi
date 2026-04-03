CREATE TABLE sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(30) NOT NULL UNIQUE,
    cpf VARCHAR(20) NULL,
    nome VARCHAR(255) NULL,
    telefone VARCHAR(30) NULL,
    current_flow VARCHAR(100) NULL,
    current_step VARCHAR(100) NULL,
    selected_service VARCHAR(100) NULL,
    selected_date VARCHAR(20) NULL,
    selected_time VARCHAR(20) NULL,
    pending_action VARCHAR(100) NULL,
    context_json LONGTEXT NULL,
    last_interaction_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
