<?php
/**
 * Categories Management - CRUD
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$page_title = 'Manage Categories';

$error = '';
$success = '';

// Generate unique category code
function generateCategoryCode($pdo, $categoryType) {
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $categoryType), 0, 4));
    if (strlen($prefix) < 2) {
        $prefix = 'CAT';
    }
    
    $maxAttempts = 20;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $code = $prefix . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_code = ?");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    // Fallback with timestamp
    return $prefix . '-' . strtoupper(substr(md5(time()), 0, 4));
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $categoryName = trim($_POST['category_name'] ?? '');
        $categoryType = trim($_POST['category_type'] ?? 'General');
        
        if (empty($categoryName)) {
            $error = 'Category name is required.';
        } else {
            try {
                // Check for duplicate
                $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = ? AND category_type = ?");
                $stmt->execute([$categoryName, $categoryType]);
                if ($stmt->fetch()) {
                    $error = 'A category with this name and type already exists.';
                } else {
                    $categoryCode = generateCategoryCode($pdo, $categoryType);
                    $stmt = $pdo->prepare("INSERT INTO categories (category_code, category_name, category_type, active) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$categoryCode, $categoryName, $categoryType]);
                    $success = "Category '$categoryName' added successfully with code: $categoryCode";
                }
            } catch (PDOException $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'edit') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $categoryName = trim($_POST['category_name'] ?? '');
        $categoryType = trim($_POST['category_type'] ?? 'General');
        $active = isset($_POST['active']) ? 1 : 0;
        
        if (empty($categoryName) || $categoryId <= 0) {
            $error = 'Invalid category data.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE categories SET category_name = ?, category_type = ?, active = ? WHERE category_id = ?");
                $stmt->execute([$categoryName, $categoryType, $active, $categoryId]);
                $success = "Category updated successfully.";
            } catch (PDOException $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        
        if ($categoryId <= 0) {
            $error = 'Invalid category ID.';
        } else {
            try {
                // Check if category is in use
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM assets WHERE category_id = ?");
                $stmt->execute([$categoryId]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    $error = "Cannot delete: {$result['count']} asset(s) are using this category. Reassign them first or deactivate the category instead.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM categories WHERE category_id = ?");
                    $stmt->execute([$categoryId]);
                    $success = "Category deleted successfully.";
                }
            } catch (PDOException $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all categories
try {
    $categories = $pdo->query("
        SELECT c.*, 
               (SELECT COUNT(*) FROM assets a WHERE a.category_id = c.category_id) as asset_count
        FROM categories c
        ORDER BY c.category_type, c.category_name
    ")->fetchAll();
} catch (PDOException $e) {
    $categories = [];
    $error = 'Error loading categories: ' . $e->getMessage();
}

// Get unique category types for dropdown suggestions
$categoryTypes = array_unique(array_column($categories, 'category_type'));
sort($categoryTypes);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4">
        <div class="d-block mb-4 mb-md-0">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('index.php'); ?>">Dashboard</a></li>
                    <li class="breadcrumb-item active">Categories</li>
                </ol>
            </nav>
            <h1 class="h2">Manage Categories</h1>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button type="button" class="btn btn-sm btn-primary d-inline-flex align-items-center" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="fas fa-plus me-2"></i>
                Add Category
            </button>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Assets</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No categories found.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($cat['category_code']); ?></code></td>
                            <td><strong><?php echo htmlspecialchars($cat['category_name']); ?></strong></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($cat['category_type']); ?></span></td>
                            <td>
                                <?php if ($cat['asset_count'] > 0): ?>
                                <a href="<?php echo base_url('assets/index.php?category=' . $cat['category_id']); ?>">
                                    <?php echo $cat['asset_count']; ?> asset(s)
                                </a>
                                <?php else: ?>
                                <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($cat['active']): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                            onclick="editCategory(<?php echo htmlspecialchars(json_encode($cat)); ?>)" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($cat['asset_count'] == 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="deleteCategory(<?php echo $cat['category_id']; ?>, '<?php echo htmlspecialchars(addslashes($cat['category_name'])); ?>')" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="add_category_name" class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add_category_name" name="category_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="add_category_type" class="form-label">Category Type <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add_category_type" name="category_type" list="categoryTypesList" placeholder="e.g., Computers, Equipment, Tools" required>
                        <datalist id="categoryTypesList">
                            <?php foreach ($categoryTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>">
                            <?php endforeach; ?>
                            <option value="Computers">
                            <option value="Electronics">
                            <option value="Furniture">
                            <option value="Equipment">
                            <option value="Tools">
                            <option value="Vehicles">
                            <option value="Safety">
                            <option value="Communications">
                        </datalist>
                        <small class="text-muted">Code will be auto-generated from the first 4 letters (e.g., COMP-001)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="category_id" id="edit_category_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Code</label>
                        <input type="text" class="form-control" id="edit_category_code" disabled>
                        <small class="text-muted">Code cannot be changed</small>
                    </div>
                    <div class="mb-3">
                        <label for="edit_category_name" class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_category_name" name="category_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_category_type" class="form-label">Category Type <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_category_type" name="category_type" list="categoryTypesList" required>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="edit_active" name="active">
                            <label class="form-check-label" for="edit_active">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form (hidden) -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="category_id" id="delete_category_id">
</form>

<script>
function editCategory(cat) {
    document.getElementById('edit_category_id').value = cat.category_id;
    document.getElementById('edit_category_code').value = cat.category_code;
    document.getElementById('edit_category_name').value = cat.category_name;
    document.getElementById('edit_category_type').value = cat.category_type;
    document.getElementById('edit_active').checked = cat.active == 1;
    
    const modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
    modal.show();
}

function deleteCategory(categoryId, categoryName) {
    if (confirm('Are you sure you want to delete the category "' + categoryName + '"?\n\nThis action cannot be undone.')) {
        document.getElementById('delete_category_id').value = categoryId;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
