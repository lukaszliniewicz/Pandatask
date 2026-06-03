import React, { useState } from 'react';
import { useCategories } from '../hooks/useCategories';
import { useCategoryMutations } from '../hooks/useCategoryMutations';

const CategoryManager = () => {
    const { data: categories, isLoading } = useCategories();
    const { createCategory, deleteCategory } = useCategoryMutations();
    const [newCategory, setNewCategory] = useState('');

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (!newCategory.trim()) return;
        try {
            await createCategory.mutateAsync(newCategory);
            setNewCategory('');
        } catch (error) {
            alert('Failed to create category');
        }
    };

    const handleDelete = async (id) => {
        if (confirm('Are you sure? Tasks using this category will lose it.')) {
            try {
                await deleteCategory.mutateAsync(id);
            } catch (error) {
                alert('Failed to delete category');
            }
        }
    };

    if (isLoading) return <div className="pandat69-loading">Loading categories...</div>;

    return (
        <div className="pandat69-category-manager">
            <div className="pandat69-category-list-container">
                <ul className="pandat69-category-list">
                    {categories && categories.length > 0 ? (
                        categories.map(cat => (
                            <li key={cat.id} className="pandat69-category-item">
                                <span className="pandat69-category-name">{cat.name}</span>
                                <button 
                                    type="button" 
                                    className="pandat69-icon-button pandat69-delete-category-btn" 
                                    onClick={() => handleDelete(cat.id)}
                                    title="Delete Category"
                                >
                                    &times;
                                </button>
                            </li>
                        ))
                    ) : (
                        <li className="pandat69-no-categories">No categories found.</li>
                    )}
                </ul>
            </div>
            
            <form className="pandat69-form pandat69-add-category-form" onSubmit={handleSubmit}>
                <div className="pandat69-form-field">
                    <label>New Category Name</label>
                    <input 
                        type="text" 
                        className="pandat69-input" 
                        value={newCategory} 
                        onChange={(e) => setNewCategory(e.target.value)}
                        required
                    />
                </div>
                <div className="pandat69-form-actions">
                    <button type="submit" className="pandat69-button pandat69-add-category-btn" disabled={createCategory.isPending}>
                        {createCategory.isPending ? 'Adding...' : 'Add Category'}
                    </button>
                </div>
            </form>
        </div>
    );
};

export default CategoryManager;
