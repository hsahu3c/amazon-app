// print("connected");

let feed_server_side_details = db.feed_server_side.findOne({ "_id": ObjectId(id) });
// printjson (feed_server_side_details);
// print(filters);
let filters = JSON.parse(feed_server_side_details['filter']);
let target_ids = feed_server_side_details['target_ids'];
let profileName = feed_server_side_details['name'];
let profileId = feed_server_side_details['profileId'];
let type = feed_server_side_details['type'];

// printjson(filters);
//  let data = db.refine_product.find(filters);
//         // printjson(data);

// data.forEach(element => {
//     print("element") 
//     printjson(element)
// });

// class UpdateProfileProduct {
    // constructor(product_container) {
        this.setLimit = 2000;
        this.filter_table = db.refine_product;
        this.limitLeft = this.setLimit;
        this.product_container = db.product_container;
        this.bulkRefine = this.filter_table.initializeUnorderedBulkOp();
        this.bulkProduct = this.product_container.initializeUnorderedBulkOp()
        initiateTargetUpdate();
    // }
    function initiateTargetUpdate() {

        let data = this.filter_table.find(filters);

        data.forEach((eachRow) => {
            updateRefineProduct(eachRow);
            updateProductContainer(eachRow.source_product_id);

            if (this.limitLeft == 0) {
                updateMongo();
                // print("executed");
            }
        });

        if (this.limitLeft < this.setLimit) {
            updateMongo();
            // print("executed");
        }
        db.profile.update({ '_id': profileId }, { '$set': { 'product_update_in_progress': false } ,'$unset': {'total_count': 1}});
        db.feed_server_side.deleteOne({ '_id': ObjectId(id) });
    }

    function prepareProfileData(profileId, profileName, target_ids, type) {
        let temp = [];
        target_ids.map((id) => {
            temp.push({
                "profile_id": profileId, 'profile_name': profileName, target_shop_id: id, type: type
            })
        })
        return temp;
    }

    function updateRefineProduct(eachRow) {
        this.limitLeft--;
        this.bulkRefine.find(findQuery(eachRow))
            .upsert()
            .update({ '$set': { 'profile': { "profile_name": profileName, "profile_id": profileId, "type": type } } });
    }

    function updateProductContainer(source_product_id) {
        this.limitLeft -= 2;
        this.bulkProduct.find({ "source_product_id": source_product_id }).update({
            "$pull": {
                'profile': { 'target_shop_id': { "$in": target_ids } }
            },

        });
        this.bulkProduct.find({ "source_product_id": source_product_id }).update({
            "$push": {
                'profile': { "$each": prepareProfileData(profileId, profileName, target_ids, type) }
            }
        });
    }

    function findQuery(product) {
        return {
            user_id: product['user_id'],
            container_id: product['container_id'],
            source_product_id: product['source_product_id'],
            source_shop_id: product['source_shop_id'],
            target_shop_id: product['target_shop_id']
        }
    }

    function updateMongo() {
        this.bulkRefine.execute();
        this.bulkProduct.execute();
        this.bulkRefine = this.filter_table.initializeUnorderedBulkOp();
        this.bulkProduct = this.product_container.initializeUnorderedBulkOp();
        this.limitLeft = this.setLimit;
    }

// }

// var connection = new UpdateProfileProduct(db.product_container);


// mongo home --authenticationDatabase admin -u root -p cedcommerce --eval "var id = '6331210b37e363e46500d682' "  /home/cedcoss/Desktop/amazon-multi-account/app/home/app/code/connector/Components/Profile/UpdateProfile.js